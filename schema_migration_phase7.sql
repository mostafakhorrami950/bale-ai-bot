-- ================================================================
-- PHASE 7: AI Integration Layer — Database Migration
-- ================================================================
-- This migration adds/updates tables needed for AI image generation.
-- It is safe to run multiple times (uses IF NOT EXISTS / IGNORE).
-- ================================================================

-- 1. Ensure bot_state table exists with all required columns
CREATE TABLE IF NOT EXISTS bot_state (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    state VARCHAR(100) DEFAULT NULL,
    photo_base64 LONGTEXT DEFAULT NULL COMMENT 'Base64 encoded photo for img2img',
    extra_data TEXT DEFAULT NULL COMMENT 'Additional data (e.g. temp file path)',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Ensure ai_models table exists
CREATE TABLE IF NOT EXISTS ai_models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    provider VARCHAR(50) DEFAULT 'gapgpt',
    cost_per_image INT NOT NULL COMMENT 'Credit cost per image generation',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Ensure ai_requests table exists (tracks all AI generations)
CREATE TABLE IF NOT EXISTS ai_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    model_id INT NOT NULL,
    prompt TEXT,
    image_type ENUM('text2img', 'img2img') DEFAULT 'text2img',
    status ENUM('success', 'failed') DEFAULT 'success',
    reference_id VARCHAR(255) UNIQUE COMMENT 'Used for idempotency',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Ensure credit_ledger table exists (for idempotent deductions)
CREATE TABLE IF NOT EXISTS credit_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount INT NOT NULL,
    type ENUM('charge', 'deduction') NOT NULL,
    model_key VARCHAR(50) DEFAULT NULL,
    reference_id VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reference_id (reference_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If reference_id column already exists but lacks index, add it:
SET @dbname = DATABASE();
SET @existing = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                 WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'credit_ledger' 
                 AND COLUMN_NAME = 'reference_id' AND COLUMN_KEY = '');
SET @sql = IF(@existing > 0, 
    'ALTER TABLE credit_ledger ADD INDEX idx_reference_id (reference_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Insert default AI model if not exists
INSERT IGNORE INTO ai_models (id, name, provider, cost_per_image, is_active) 
VALUES (1, 'dall-e-3', 'gapgpt', 2, 1);

-- 6. Ensure users table has credits column as INT (not DECIMAL)
ALTER TABLE users MODIFY COLUMN credits INT DEFAULT 0;

-- 7. Ensure processed_updates table exists for deduplication
CREATE TABLE IF NOT EXISTS processed_updates (
    update_id BIGINT PRIMARY KEY,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Ensure bot_logs table exists
CREATE TABLE IF NOT EXISTS bot_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    context JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;