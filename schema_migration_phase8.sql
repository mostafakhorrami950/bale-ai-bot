-- ================================================================
-- PHASE 8: Payment Integration (Zibal) — Database Migration
-- ================================================================
-- This migration adds tables for Zibal payment integration.
-- Safe to run multiple times (uses IF NOT EXISTS / IGNORE).
-- ================================================================

-- 1. Payment plans table (configurable credit packages)
CREATE TABLE IF NOT EXISTS payment_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    credits INT NOT NULL,
    price_rial INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default payment plans (safe: uses IGNORE)
INSERT IGNORE INTO payment_plans (plan_id, name, credits, price_rial) VALUES
('basic', 'پایه', 50, 49000),
('standard', 'استاندارد', 150, 139000),
('premium', 'حرفه‌ای', 500, 449000);

-- 2. Payments table (tracks each payment attempt)
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    track_id VARCHAR(100) UNIQUE NOT NULL COMMENT 'Zibal track ID',
    amount_rial INT NOT NULL,
    credits INT NOT NULL,
    plan_id VARCHAR(50) NULL,
    status ENUM('pending', 'verified', 'failed') DEFAULT 'pending',
    ref_number VARCHAR(100) NULL COMMENT 'Zibal reference number',
    verified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_track_id (track_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Payment logs table (audit trail for all Zibal API calls)
CREATE TABLE IF NOT EXISTS payment_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    track_id VARCHAR(100) NULL,
    action VARCHAR(50) NOT NULL COMMENT 'request or verify',
    request_data TEXT NULL,
    response_data TEXT NULL,
    status VARCHAR(20) NOT NULL COMMENT 'success or failed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Ensure credit_ledger has reference_id index for idempotency lookups
SET @dbname = DATABASE();
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
                   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'credit_ledger' 
                   AND INDEX_NAME = 'idx_reference_id');
SET @sql = IF(@idx_exists = 0, 
    'CREATE INDEX idx_reference_id ON credit_ledger (reference_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Ensure bot_logs table exists
CREATE TABLE IF NOT EXISTS bot_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    context JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;