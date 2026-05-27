-- ==============================================================
-- Migration: Auto-Verify Payments (Cron support)
-- ==============================================================
-- Adds necessary columns for auto-verification of Zibal payments.
-- Safe to run multiple times (uses IF NOT EXISTS / IGNORE).

-- 1. Add checked_at column to track last inquiry time for pending payments
SET @dbname = DATABASE();
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payments' 
                   AND COLUMN_NAME = 'inquiry_count');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE payments ADD COLUMN inquiry_count INT DEFAULT 0 AFTER verified_at',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col2_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payments' 
                    AND COLUMN_NAME = 'last_inquiry_at');
SET @sql2 = IF(@col2_exists = 0, 
    'ALTER TABLE payments ADD COLUMN last_inquiry_at TIMESTAMP NULL AFTER inquiry_count',
    'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 2. Add index for auto-verify queries (pending + track_id not null + old enough)
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
                   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payments' 
                   AND INDEX_NAME = 'idx_auto_verify');
SET @sql3 = IF(@idx_exists = 0, 
    'CREATE INDEX idx_auto_verify ON payments (status, created_at)',
    'SELECT 1');
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;