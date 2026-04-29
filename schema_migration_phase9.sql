-- ================================================================
-- PHASE 9: Professional Admin Panel — Database Migration
-- ================================================================
-- This migration adds the admin_actions table for audit logging.
-- Safe to run multiple times (uses IF NOT EXISTS).
-- ================================================================

-- 1. Admin actions audit log
CREATE TABLE IF NOT EXISTS admin_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_username VARCHAR(100) NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) NULL,
    target_id INT NULL,
    details TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin (admin_username),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Admin panel audit log';

-- 2. Ensure settings table exists with key_name unique
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(100) UNIQUE NOT NULL,
    value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default settings if missing (safe: IGNORE)
INSERT IGNORE INTO settings (key_name, value) VALUES
('required_channel_id', '@mobix_tube'),
('required_channel_link', 'https://t.me/mobix_tube'),
('free_daily_limit', '1'),
('initial_credit', '15'),
('welcome_message', 'به ربات هوش مصنوعی خوش آمدید!'),
('maintenance_mode', 'off');

-- 3. Ensure ai_models table has provider column
SET @hasProvider = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_models' 
                    AND COLUMN_NAME = 'provider');
SET @sql = IF(@hasProvider = 0, 
    'ALTER TABLE ai_models ADD COLUMN provider VARCHAR(50) DEFAULT ''gapgpt'' AFTER name',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Ensure api_keys table has provider column
SET @hasApiProvider = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys' 
                       AND COLUMN_NAME = 'provider');
SET @sql2 = IF(@hasApiProvider = 0, 
    'ALTER TABLE api_keys ADD COLUMN provider VARCHAR(50) DEFAULT ''gapgpt'' AFTER id',
    'SELECT 1');
PREPARE stmt FROM @sql2;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Ensure users table has needed columns
SET @hasPhone = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' 
                 AND COLUMN_NAME = 'phone_number');
SET @sql3 = IF(@hasPhone = 0, 
    'ALTER TABLE users ADD COLUMN phone_number VARCHAR(20) NULL AFTER username',
    'SELECT 1');
PREPARE stmt FROM @sql3;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hasLastActive = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' 
                      AND COLUMN_NAME = 'last_active_at');
SET @sql4 = IF(@hasLastActive = 0, 
    'ALTER TABLE users ADD COLUMN last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER updated_at',
    'SELECT 1');
PREPARE stmt FROM @sql4;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hasCredits = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' 
                   AND COLUMN_NAME = 'credits');
SET @sql5 = IF(@hasCredits = 0, 
    'ALTER TABLE users ADD COLUMN credits INT DEFAULT 0 AFTER phone_number',
    'SELECT 1');
PREPARE stmt FROM @sql5;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;