-- DB setup.sql
-- คำสั่งสร้างและปรับปรุงโครงสร้างฐานข้อมูล MySQL / MariaDB สำหรับระบบเลขา AI (Project Antigravity)
-- รองรับการเก็บข้อมูลลูกค้า/Owner แบบเข้ารหัส และข้อมูลระบบสมัครสมาชิก/ทดลองใช้ 15 วัน

CREATE DATABASE IF NOT EXISTS `antigravity_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `antigravity_db`;

-- ลบตารางเดิมถ้ามีอยู่ เพื่อความสะอาดในการติดตั้งใหม่
DROP TABLE IF EXISTS `tasks`;
DROP TABLE IF EXISTS `reject_logs`;
DROP TABLE IF EXISTS `owners`;
DROP TABLE IF EXISTS `leads`;
DROP TABLE IF EXISTS `users`;

-- 1. ตารางผู้ใช้งานระบบ (Users)
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_user_id` VARCHAR(255) UNIQUE NOT NULL,                  -- รหัส LINE User ID
    `user_name` VARCHAR(100) NOT NULL,                            -- ชื่อผู้ใช้งานระบบ
    `job_title` VARCHAR(100) DEFAULT NULL,                        -- ทำงานอะไร
    `work_context` TEXT DEFAULT NULL,                             -- บริบทการทำงานคือยังไง
    `has_teammates` TINYINT DEFAULT 0,                            -- มีเพื่อนร่วมทีมไหม (0 = ไม่มี, 1 = มี)
    `teammate_roles` TEXT DEFAULT NULL,                           -- เพื่อนร่วมทีมทำอะไรบ้าง
    `agent_areas` TEXT DEFAULT NULL,                              -- เพื่อนร่วมงานดูแลพื้นที่ไหนบ้าง (กรณีเอเจนท์)
    `reject_cases` TEXT DEFAULT NULL,                             -- เคสการ Reject มีแบบไหนบ้าง (เก็บเป็น Text หรือ JSON)
    `reject_reasons` TEXT DEFAULT NULL,                           -- แต่ละการ Reject มีเหตุผลอะไรบ้าง
    `bot_name` VARCHAR(100) DEFAULT 'เลขา AI',                     -- ชื่อของบอท AI
    `persona_style` VARCHAR(50) DEFAULT 'formal_polite',          -- สไตล์การตอบกลับ (formal_polite, casual_friendly, assertive_professional)
    `business_type` VARCHAR(100) DEFAULT 'Real Estate',           -- ประเภทธุรกิจ
    `google_sheet_id` VARCHAR(255) NULL,                          -- รหัส Google Sheet ID
    `google_drive_id` VARCHAR(255) DEFAULT NULL,                  -- รหัส Google Drive Folder ID
    `encryption_key` VARCHAR(255) NOT NULL,                       -- คีย์เฉพาะบุคคลสำหรับใช้เข้ารหัส
    `first_name` VARCHAR(100) DEFAULT NULL,                        -- ชื่อจริง
    `last_name` VARCHAR(100) DEFAULT NULL,                         -- นามสกุล
    `phone` VARCHAR(50) DEFAULT NULL,                             -- เบอร์โทรศัพท์
    `consent_required` TINYINT DEFAULT 1,                         -- ยินยอมข้อตกลง (บังคับ)
    `consent_optional` TINYINT DEFAULT 0,                         -- ยินยอมส่งข้อมูล AI (ไม่บังคับ)
    `trial_ends_at` DATETIME NOT NULL,                            -- วันสิ้นสุดการทดลองใช้ฟรี 15 วัน
    `is_subscribed` TINYINT DEFAULT 0,                            -- สถานะชำระเงิน (0 = ยังไม่ชำระ/ใช้ฟรี, 1 = ชำระเงินแล้ว)
    `is_lifetime_free` TINYINT UNSIGNED NOT NULL DEFAULT 0,         -- ใช้ฟรีตลอดชีพ (ไม่หมด trial)
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_line_user_id` (`line_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ตารางเก็บข้อมูลลีดลูกค้า (Leads)
CREATE TABLE `leads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,                                       -- เชื่อมโยงผู้ใช้ที่เป็นเจ้าของ
    `lead_code` VARCHAR(50) NOT NULL,                             -- รหัสดีล เช่น L042
    `lead_name_enc` TEXT DEFAULT NULL,                            -- ชื่อลูกค้า (เข้ารหัส)
    `project_enc` TEXT DEFAULT NULL,                             -- โครงการ (เข้ารหัส)
    `phone_enc` TEXT DEFAULT NULL,                                -- เบอร์โทร (เข้ารหัส)
    `line_id_enc` TEXT DEFAULT NULL,                              -- Line ID (เข้ารหัส)
    `budget_enc` TEXT DEFAULT NULL,                               -- งบประมาณ (เข้ารหัส)
    `potential` VARCHAR(10) DEFAULT 'B',                          -- ระดับความรีบ A, B, C
    `occupation_enc` TEXT DEFAULT NULL,                           -- อาชีพ (เข้ารหัส)
    `contact_date` DATE DEFAULT NULL,                             -- วันที่ติดต่อเข้ามา
    `target_date_enc` TEXT DEFAULT NULL,                          -- วันที่/เดือนแพลนจะซื้อหรือเข้าอยู่ (เข้ารหัส)
    `pain_point_enc` TEXT DEFAULT NULL,                           -- Pain point (เข้ารหัส)
    `requirement_enc` TEXT DEFAULT NULL,                          -- Requirement (เข้ารหัส)
    `financials_enc` TEXT DEFAULT NULL,                           -- Financials (เข้ารหัส)
    `residents_count_enc` TEXT DEFAULT NULL,                      -- พักอาศัยกี่คน (เข้ารหัส)
    `parking_count_enc` TEXT DEFAULT NULL,                        -- จอดรถกี่คน (เข้ารหัส)
    `background_enc` TEXT DEFAULT NULL,                           -- Background ประวัติลูกค้า (เข้ารหัส)
    `current_update_enc` TEXT DEFAULT NULL,                       -- อัปเดตเคสปัจจุบัน ว่าถึงไหนแล้ว (เข้ารหัส)
    `status` ENUM('Call', 'Follow', 'Appointment', 'Show', 'Nego', 'Close', 'Bank', 'Win', 'Hold_Reject', 'Rejected') DEFAULT 'Call', -- สถานะปัจจุบัน
    `next_plan_action_enc` TEXT DEFAULT NULL,                      -- แผนงานถัดไป (เข้ารหัส)
    `next_plan_date` DATE DEFAULT NULL,                           -- วันที่ของแผนงานถัดไป
    `customer_insight_enc` TEXT DEFAULT NULL,                     -- สรุปเชิงลึกลูกค้า (เข้ารหัส)
    `deal_context_enc` TEXT DEFAULT NULL,                         -- บริบทดีล / ข้อความดิบ (เข้ารหัส)
    `priority_score` TINYINT DEFAULT 3,                           -- คะแนนความสำคัญ 1-5
    `owner_code` VARCHAR(50) DEFAULT NULL,                        -- รหัสทรัพย์ที่ลูกค้าสนใจ เช่น O055
    `chat_image_url` VARCHAR(512) DEFAULT NULL,                   -- รูปแชท/ทรัพย์จากลูกค้า
    `chat_photos_link_enc` TEXT DEFAULT NULL,                     -- ลิงก์โฟลเดอร์ Drive รูปแชท (เข้ารหัส)
    `product_price_enc` TEXT DEFAULT NULL,                        -- ราคาทรัพย์ (ถ้าไม่ผูก owner)
    `win_date` DATE DEFAULT NULL,                                 -- วันที่ปิดดีล (Win)
    `win_price_enc` TEXT DEFAULT NULL,                            -- ราคาปิดดีล (เข้ารหัส)
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_user_lead` (`user_id`, `lead_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. ตารางเก็บข้อมูลฝั่งเจ้าของทรัพย์สิน (Owners)
CREATE TABLE `owners` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,                                       -- เชื่อมโยงผู้ใช้ที่เป็นเจ้าของ
    `code_list` VARCHAR(50) NOT NULL,                             -- Code list รหัสทรัพย์สิน เช่น O055
    `owner_name_enc` TEXT DEFAULT NULL,                            -- ชื่อเจ้าของ (เข้ารหัส)
    `project_enc` TEXT DEFAULT NULL,                             -- โครงการ (เข้ารหัส) — legacy / EN
    `project_name_en_enc` TEXT DEFAULT NULL,                     -- ชื่อโครงการ EN (เข้ารหัส)
    `project_name_th_enc` TEXT DEFAULT NULL,                     -- ชื่อโครงการ TH (เข้ารหัส)
    `cover_image_url` VARCHAR(512) DEFAULT NULL,                 -- รูปปก Google Drive
    `photos_link_enc` TEXT DEFAULT NULL,                         -- ลิงก์โฟลเดอร์รูปทั้งหมด (เข้ารหัส)
    `listing_source` VARCHAR(50) DEFAULT NULL,                   -- survey, FB, livinginsider, other
    `listing_date` DATE DEFAULT NULL,                             -- วันที่รับเข้าลิส
    `marketing_date` DATE DEFAULT NULL,                           -- วันที่ทำการตลาด
    `has_deed` TINYINT DEFAULT NULL,                              -- 1=มีโฉนด 0=ไม่มี
    `owner_asking_price_enc` TEXT DEFAULT NULL,                   -- ราคาที่เจ้าของตั้ง (เข้ารหัส)
    `sold_date` DATE DEFAULT NULL,                                -- วันที่ขายได้
    `maid_enc` TEXT DEFAULT NULL,                                 -- ห้องแม่บ้าน (เข้ารหัส)
    `last_contact_date` DATE DEFAULT NULL,                        -- วันที่ติดต่อล่าสุด
    `contact_summary_enc` TEXT DEFAULT NULL,                      -- สรุปผลการติดต่อล่าสุด (เข้ารหัส)
    `price_consult_enc` TEXT DEFAULT NULL,                        -- Consult ราคา (เข้ารหัส)
    `marketing_status` VARCHAR(50) DEFAULT 'ลงการตลาดแล้ว',          -- สถานะ เช่น ลงการตลาดแล้ว, ข้อมูลยังไม่ครบ
    `incomplete_details_enc` TEXT DEFAULT NULL,                   -- เหตุผลที่ข้อมูลไม่ครบ ขาดอะไร (เข้ารหัส)
    `property_type_enc` TEXT DEFAULT NULL,                        -- Property Type (เข้ารหัส)
    `phone_enc` TEXT DEFAULT NULL,                                -- เบอร์โทร (เข้ารหัส)
    `line_id_enc` TEXT DEFAULT NULL,                              -- Line ID (เข้ารหัส)
    `zone_enc` TEXT DEFAULT NULL,                                 -- โซน (เข้ารหัส)
    `soi_enc` TEXT DEFAULT NULL,                                  -- ซอย (เข้ารหัส)
    `area_enc` TEXT DEFAULT NULL,                                 -- แหล่ง/พื้นที่กว้างๆ (เข้ารหัส)
    `location_grade_enc` TEXT DEFAULT NULL,                       -- เกรดทำเล (เข้ารหัส)
    `bts_mrt_srt_enc` TEXT DEFAULT NULL,                          -- รถไฟฟ้า (เข้ารหัส)
    `arl_enc` TEXT DEFAULT NULL,                                  -- แอร์พอร์ตลิงก์ (เข้ารหัส)
    `bed_enc` TEXT DEFAULT NULL,                                  -- ห้องนอน (เข้ารหัส)
    `bath_enc` TEXT DEFAULT NULL,                                 -- ห้องน้ำ (เข้ารหัส)
    `unit_no_enc` TEXT DEFAULT NULL,                              -- บ้านเลขที่/ห้องเลขที่ (เข้ารหัส)
    `area_rai_enc` TEXT DEFAULT NULL,                             -- ไร่ (เข้ารหัส)
    `area_ngan_enc` TEXT DEFAULT NULL,                            -- งาน (เข้ารหัส)
    `area_sqwa_enc` TEXT DEFAULT NULL,                            -- ตร.ว. (เข้ารหัส)
    `area_sqm_enc` TEXT DEFAULT NULL,                             -- ตร.ม. (เข้ารหัส)
    `floor_enc` TEXT DEFAULT NULL,                                -- ชั้น (เข้ารหัส)
    `parking_enc` TEXT DEFAULT NULL,                              -- ที่จอดรถ (เข้ารหัส)
    `direction_enc` TEXT DEFAULT NULL,                            -- ทิศ (เข้ารหัส)
    `asking_price_enc` TEXT DEFAULT NULL,                         -- ราคาขายเสนอ (เข้ารหัส)
    `rental_price_enc` TEXT DEFAULT NULL,                         -- ราคาเช่าเสนอ (เข้ารหัส)
    `selling_condition` VARCHAR(100) DEFAULT '50/50 Transfer Fee', -- 50/50 Transfer Fee / All Included / Transfer Fee Not Included
    `map_url_enc` TEXT DEFAULT NULL,                              -- แผนที่ลิงก์ (เข้ารหัส)
    `availability_status` VARCHAR(50) DEFAULT 'ยังขายอยู่',          -- ยังขายอยู่, ยกเลิกการขาย, ขายได้แล้ว
    `sold_by_enc` TEXT DEFAULT NULL,                              -- ใครขายได้ (เข้ารหัส)
    `sold_price_enc` TEXT DEFAULT NULL,                           -- ขายราคาเท่าไร (เข้ารหัส)
    `sales_status` VARCHAR(50) DEFAULT 'Sale',                    -- Sale, sale&available, rent, sale with tenant, sold, cancel
    `owner_urgency` VARCHAR(10) DEFAULT 'B',                       -- ระดับความร้อนในการขาย a, b, c
    `selling_reason_enc` TEXT DEFAULT NULL,                       -- เหตุผลการขายของ Owner (เข้ารหัส)
    `selling_timeline_enc` TEXT DEFAULT NULL,                     -- ไทม์ไลน์การขายของ Owner (เข้ารหัส)
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_user_owner` (`user_id`, `code_list`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3b. ประวัติติดต่อเจ้าของทรัพย์
CREATE TABLE `owner_contact_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `owner_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `contact_date` DATE NOT NULL,
    `note_enc` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`owner_id`) REFERENCES `owners` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_owner_contact` (`owner_id`, `contact_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3c. ประวัติปรับราคาทรัพย์
CREATE TABLE `owner_price_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `owner_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `log_date` DATE NOT NULL,
    `old_price_enc` TEXT DEFAULT NULL,
    `new_price_enc` TEXT NOT NULL,
    `changed_by` VARCHAR(20) DEFAULT 'owner' COMMENT 'owner=เจ้าของปรับ agent=ที่ปรึกษาปรับ',
    `note_enc` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`owner_id`) REFERENCES `owners` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_owner_price` (`owner_id`, `log_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3d. ประวัติอัปเดตสถานะลีด (Pipeline)
CREATE TABLE `lead_status_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `lead_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `status` VARCHAR(30) NOT NULL,
    `note_enc` TEXT DEFAULT NULL,
    `log_date` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_lead_status` (`lead_id`, `log_date` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3e. Stage Outcome Matrix (Phase 2) — ผลลัพธ์รายขั้นต่อ Lead
CREATE TABLE `lead_stage_events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `lead_id` INT NOT NULL,
    `stage` VARCHAR(20) NOT NULL,
    `outcome` VARCHAR(10) NOT NULL,
    `note_enc` TEXT DEFAULT NULL,
    `event_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_user_lead` (`user_id`, `lead_id`),
    INDEX `idx_user_stage_date` (`user_id`, `stage`, `event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. ตารางบันทึกประวัติการปฏิเสธดีล (Reject Logs)
CREATE TABLE `reject_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,                                       -- เจ้าของดีล
    `target_code` VARCHAR(50) NOT NULL,                           -- รหัสดีล/ลีดที่โดนรีเจค
    `reject_case` VARCHAR(100) NOT NULL,                          -- ประเภทกรณีที่รีเจค (สอดคล้องกับที่ลงทะเบียนไว้)
    `reject_reason` VARCHAR(255) NOT NULL,                        -- เหตุผลในการรีเจคจริงๆ
    `raw_message_enc` TEXT DEFAULT NULL,                          -- ข้อความดิบของลูกค้ารอบข้าง (เข้ารหัส)
    `rejected_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. ตารางงาน/การติดตามผล (Tasks)
CREATE TABLE `tasks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `title_enc` TEXT NOT NULL,                                    -- หัวข้องาน (เข้ารหัส)
    `due_date` DATE DEFAULT NULL,                                 -- กำหนดส่งงาน
    `due_time` TIME DEFAULT NULL,                                 -- เวลาแจ้งเตือน (สำหรับลิงก์แจ้งเตือน LINE)
    `is_completed` TINYINT DEFAULT 0,                            -- 0 = ยังไม่เสร็จ, 1 = เสร็จแล้ว
    `priority` TINYINT DEFAULT 0,                                -- Eisenhower: 1=ด่วน+สำคัญ 2=สำคัญไม่ด่วน 3=ด่วนไม่สำคัญ 4=ไม่ด่วนไม่สำคัญ
    `lead_code` VARCHAR(50) DEFAULT NULL,                         -- ลิงก์หารหัสลีด (หากมี)
    `owner_code` VARCHAR(50) DEFAULT NULL,                        -- ลิงก์หารหัส owner (หากมี)
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. ตารางตั้งเป้า Pipeline (รายได้รายเดือน + conversion funnel)
CREATE TABLE `pipeline_settings` (
    `user_id` INT PRIMARY KEY,
    `target_month` CHAR(7) NOT NULL DEFAULT '',
    `monthly_target` INT UNSIGNED DEFAULT 0,
    `commission_per_deal` INT UNSIGNED DEFAULT 50000,
    `project_target_price` INT UNSIGNED DEFAULT 5000000,
    `need_project` INT UNSIGNED DEFAULT 0,
    `need_lead` INT UNSIGNED DEFAULT 0,
    `need_app` INT UNSIGNED DEFAULT 0,
    `need_showing` INT UNSIGNED DEFAULT 0,
    `need_nego` INT UNSIGNED DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;