-- ==============================================================
-- Phase 12: Chat Conversations + Token-based billing
-- ==============================================================

-- 1. New ai_models columns for per-character billing
ALTER TABLE ai_models
  ADD COLUMN cost_per_input_char DECIMAL(10,6) DEFAULT 0.000001 AFTER cost_per_image,
  ADD COLUMN cost_per_output_char DECIMAL(10,6) DEFAULT 0.000002 AFTER cost_per_input_char,
  ADD COLUMN free_model TINYINT(1) DEFAULT 0 AFTER is_active;

-- 2. Chat conversations table
CREATE TABLE IF NOT EXISTS chat_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    model VARCHAR(200) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    status ENUM('active','archived') DEFAULT 'active',
    total_input_chars INT DEFAULT 0,
    total_output_chars INT DEFAULT 0,
    total_cost_credits INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Chat messages table
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    role ENUM('user','assistant','system') NOT NULL,
    content TEXT NOT NULL,
    file_type VARCHAR(50) DEFAULT NULL,
    file_content TEXT DEFAULT NULL,
    input_chars INT DEFAULT 0,
    output_chars INT DEFAULT 0,
    cost_input_credits INT DEFAULT 0,
    cost_output_credits INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_created (created_at),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Update default models with per-char costs
UPDATE ai_models SET
  cost_per_input_char = 0.000001,
  cost_per_output_char = 0.000002,
  free_model = 0
WHERE provider = 'gapgpt';

UPDATE ai_models SET
  cost_per_input_char = 0.000001,
  cost_per_output_char = 0.000002,
  free_model = 0
WHERE provider = 'openrouter';

UPDATE ai_models SET
  cost_per_input_char = 0.000002,
  cost_per_output_char = 0.000003,
  free_model = 0
WHERE provider = 'metisai';