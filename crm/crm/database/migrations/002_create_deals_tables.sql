-- ============================================
-- Pipelines & Deals Tables
-- ============================================

-- Pipelines Table
CREATE TABLE IF NOT EXISTS `pipelines` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT,
    `is_default` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stages Table (for each pipeline)
CREATE TABLE IF NOT EXISTS `stages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `pipeline_id` INT NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `color` VARCHAR(7) DEFAULT '#6B7280',
    `order_index` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`pipeline_id`) REFERENCES `pipelines`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contacts Table
CREATE TABLE IF NOT EXISTS `contacts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(200) NOT NULL,
    `phone` VARCHAR(20),
    `email` VARCHAR(100),
    `national_code` VARCHAR(10),
    `passport_number` VARCHAR(50),
    `address` TEXT,
    `company` VARCHAR(200),
    `notes` TEXT,
    `source` VARCHAR(100) COMMENT 'نحوه آشنایی',
    `tags` TEXT COMMENT 'برچسب‌ها',
    `created_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_phone` (`phone`),
    INDEX `idx_name` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Deals Table
CREATE TABLE IF NOT EXISTS `deals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT,
    `amount` DECIMAL(15,2) DEFAULT 0,
    `currency` VARCHAR(10) DEFAULT 'IRR',
    `pipeline_id` INT NOT NULL,
    `stage_id` INT NOT NULL,
    `contact_id` INT,
    `assigned_to` INT COMMENT 'مسئول معامله',
    `source` VARCHAR(100) COMMENT 'نحوه آشنایی',
    `probability` INT DEFAULT 0 COMMENT 'درصد احتمال موفقیت',
    `expected_close_date` DATE,
    `closed_at` TIMESTAMP NULL,
    `lost_reason` TEXT COMMENT 'دلیل عدم موفقیت',
    `is_lost` TINYINT(1) DEFAULT 0,
    `is_won` TINYINT(1) DEFAULT 0,
    `tags` TEXT,
    `created_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`pipeline_id`) REFERENCES `pipelines`(`id`),
    FOREIGN KEY (`stage_id`) REFERENCES `stages`(`id`),
    FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_stage` (`stage_id`),
    INDEX `idx_assigned` (`assigned_to`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Deal Activities (notes, calls, meetings, etc.)
CREATE TABLE IF NOT EXISTS `deal_activities` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `deal_id` INT NOT NULL,
    `user_id` INT,
    `type` ENUM('note', 'call', 'meeting', 'email', 'sms', 'follow_up', 'other') DEFAULT 'note',
    `subject` VARCHAR(200),
    `description` TEXT,
    `activity_date` DATETIME,
    `is_done` TINYINT(1) DEFAULT 0,
    `reminder_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_deal_activities` (`deal_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Payments Table
-- ============================================
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `deal_id` INT,
    `contact_id` INT,
    `amount` DECIMAL(15,2) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'IRR',
    `payment_type` ENUM('online', 'cash', 'transfer', 'check') DEFAULT 'online',
    `status` ENUM('pending', 'success', 'failed', 'expired') DEFAULT 'pending',
    `track_id` VARCHAR(100) COMMENT 'Zibal track ID',
    `ref_number` VARCHAR(100) COMMENT 'شماره مرجع پرداخت',
    `card_number` VARCHAR(20) COMMENT 'شماره کارت ماسک شده',
    `description` TEXT,
    `paid_at` TIMESTAMP NULL,
    `created_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_track_id` (`track_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- SMS History Table
-- ============================================
CREATE TABLE IF NOT EXISTS `sms_history` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `deal_id` INT,
    `contact_id` INT,
    `recipient` VARCHAR(20) NOT NULL,
    `message` TEXT NOT NULL,
    `pattern_code` VARCHAR(50),
    `status` ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
    `message_outbox_id` BIGINT,
    `error_message` TEXT,
    `sent_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`sent_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_recipient` (`recipient`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Custom Fields Table (for extensibility)
-- ============================================
CREATE TABLE IF NOT EXISTS `custom_fields` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `entity_type` VARCHAR(50) NOT NULL COMMENT 'deals, contacts, pipelines',
    `label` VARCHAR(200) NOT NULL,
    `field_key` VARCHAR(100) NOT NULL,
    `field_type` ENUM('text', 'number', 'date', 'select', 'checkbox', 'textarea') DEFAULT 'text',
    `options` TEXT COMMENT 'JSON options for select type',
    `is_required` TINYINT(1) DEFAULT 0,
    `order_index` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_entity_key` (`entity_type`, `field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Custom Field Values
CREATE TABLE IF NOT EXISTS `custom_field_values` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `field_id` INT NOT NULL,
    `entity_id` INT NOT NULL,
    `value` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`field_id`) REFERENCES `custom_fields`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_field_entity` (`field_id`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Settings Table (feature toggles & configurations)
-- ============================================
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `setting_group` VARCHAR(50) DEFAULT 'general',
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default settings
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('app_name', 'CRM Travel Agency', 'general', 'نام سیستم'),
('app_timezone', 'Asia/Tehran', 'general', 'منطقه زمانی'),
('feature_payment_gateway', '1', 'features', 'فعال/غیرفعال کردن درگاه پرداخت'),
('feature_sms', '1', 'features', 'فعال/غیرفعال کردن سرویس پیامک'),
('feature_pipelines', '1', 'features', 'فعال/غیرفعال کردن پایپ لاین'),
('feature_reports', '1', 'features', 'فعال/غیرفعال کردن گزارشات'),
('feature_activity_log', '1', 'features', 'فعال/غیرفعال کردن لاگ فعالیت‌ها'),
('zibal_merchant', 'zibal', 'payment', 'مرچنت درگاه زیبال'),
('sms_api_token', '', 'sms', 'توکن API پنل پیامکی'),
('sms_pattern_code', '', 'sms', 'کد پترن پیش‌فرض ارسال پیامک'),
('default_currency', 'IRR', 'general', 'واحد پول پیش‌فرض');