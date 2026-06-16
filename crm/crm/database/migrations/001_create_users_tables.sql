-- ============================================
-- Users & Authentication Tables
-- ============================================

-- Roles Table
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT,
    `is_system` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permissions Table
CREATE TABLE IF NOT EXISTS `permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `group_name` VARCHAR(100),
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Role Permissions Table
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_id` INT NOT NULL,
    `permission` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_role_permission` (`role_id`, `permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100),
    `phone` VARCHAR(20),
    `role_id` INT NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `last_login` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User Activity Log
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50),
    `entity_id` INT,
    `description` TEXT,
    `ip_address` VARCHAR(45),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_action` (`user_id`, `action`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Default Permissions Data
-- ============================================
INSERT IGNORE INTO `permissions` (`name`, `slug`, `group_name`, `description`) VALUES
('مشاهده داشبورد', 'dashboard.view', 'داشبورد', 'دسترسی به داشبورد اصلی'),
('مشاهده معاملات', 'deals.view', 'معاملات', 'مشاهده لیست معاملات'),
('ایجاد معامله', 'deals.create', 'معاملات', 'ایجاد معامله جدید'),
('ویرایش معامله', 'deals.edit', 'معاملات', 'ویرایش معاملات'),
('حذف معامله', 'deals.delete', 'معاملات', 'حذف معاملات'),
('مشاهده پایپ لاین', 'pipelines.view', 'پایپ لاین', 'مشاهده پایپ لاین‌ها'),
('ایجاد پایپ لاین', 'pipelines.create', 'پایپ لاین', 'ایجاد پایپ لاین جدید'),
('ویرایش پایپ لاین', 'pipelines.edit', 'پایپ لاین', 'ویرایش پایپ لاین'),
('حذف پایپ لاین', 'pipelines.delete', 'پایپ لاین', 'حذف پایپ لاین'),
('مشاهده مخاطبان', 'contacts.view', 'مخاطبان', 'مشاهده لیست مخاطبان'),
('ایجاد مخاطب', 'contacts.create', 'مخاطبان', 'ایجاد مخاطب جدید'),
('ویرایش مخاطب', 'contacts.edit', 'مخاطبان', 'ویرایش مخاطبان'),
('حذف مخاطب', 'contacts.delete', 'مخاطبان', 'حذف مخاطبان'),
('مشاهده گزارشات', 'reports.view', 'گزارشات', 'مشاهده گزارشات'),
('خروجی گزارشات', 'reports.export', 'گزارشات', 'خروجی گرفتن از گزارشات'),
('مدیریت کاربران', 'users.manage', 'کاربران', 'مدیریت کاربران سیستم'),
('مدیریت نقش‌ها', 'roles.manage', 'نقش‌ها', 'مدیریت نقش‌های کاربری'),
('مدیریت تنظیمات', 'settings.manage', 'تنظیمات', 'مدیریت تنظیمات سیستم'),
('ارسال پیامک', 'sms.send', 'پیامک', 'ارسال پیامک به مخاطبان'),
('مشاهده پرداخت‌ها', 'payments.view', 'پرداخت', 'مشاهده تراکنش‌های پرداخت'),
('ایجاد لینک پرداخت', 'payments.create', 'پرداخت', 'ایجاد لینک پرداخت برای مشتریان'),
('مدیریت فعالیت‌ها', 'activities.manage', 'فعالیت‌ها', 'مدیریت فعالیت‌ها و یادداشت‌ها');