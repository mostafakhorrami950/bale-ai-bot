-- ============================================================
-- Memory Module - Database Migration
-- ============================================================

-- 1. Add module setting
INSERT INTO settings (key_name, value) VALUES ('memory_module_enabled', '1')
ON DUPLICATE KEY UPDATE value = '1';

-- 2. User memories table
CREATE TABLE IF NOT EXISTS user_memories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    memory_text TEXT NOT NULL,
    source_message TEXT NULL COMMENT 'Original message that triggered this memory',
    memory_type ENUM('explicit', 'extracted') DEFAULT 'explicit',
    importance INT DEFAULT 1,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_memories (user_id, is_active),
    INDEX idx_user_importance (user_id, importance DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Conversation summaries table
CREATE TABLE IF NOT EXISTS conversation_summaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    summary_text TEXT NOT NULL,
    message_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_summaries_user (user_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;