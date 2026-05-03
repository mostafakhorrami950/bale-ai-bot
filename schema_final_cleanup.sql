-- Cleanup Migration: Remove GapGPT/MetisAI references, fix ENUM types

-- غیرفعال کردن مدل‌های GapGPT و Metis
UPDATE ai_models SET is_active = 0 WHERE provider IN ('gapgpt', 'metisai');
UPDATE ai_image_models SET is_active = 0 WHERE provider IN ('gapgpt', 'metisai');
UPDATE ai_edit_models SET is_active = 0 WHERE provider IN ('gapgpt', 'metisai');

-- اصلاح ENUM به VARCHAR در credit_ledger
ALTER TABLE credit_ledger MODIFY COLUMN type VARCHAR(20) NOT NULL DEFAULT 'debit';

-- اطمینان از وجود جدول bot_logs
CREATE TABLE IF NOT EXISTS bot_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    context JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- اطمینان از وجود جدول processed_updates
CREATE TABLE IF NOT EXISTS processed_updates (
    update_id BIGINT PRIMARY KEY,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;