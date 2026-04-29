-- Add model_config JSON column to ai_models for per-model API parameters
ALTER TABLE ai_models ADD COLUMN model_config JSON NULL AFTER cost_per_image;

-- Example config for different models:
-- {"metisai":{"model_name":"openai","model_model":"gpt-image-2","image_param":"image","supports_image":false,"supports_mask":false,"size":"auto","quality":"medium","output_format":"png"}}
-- {"metisai":{"model_name":"google","model_model":"nano-banana","image_param":"image_input","supports_image":true,"supports_mask":false,"size":"auto"}}

-- Update existing MetisAI models with default config
UPDATE ai_models SET model_config = '{"metisai":{"model_name":"openai","model_model":"gpt-image-2","image_param":"image","supports_image":false,"supports_mask":false,"size":"auto"}}' WHERE provider = 'metisai' AND name LIKE 'gpt-image-2%';

-- uploaded_files table
CREATE TABLE IF NOT EXISTS uploaded_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    original_filename VARCHAR(255),
    local_path VARCHAR(500) NOT NULL,
    public_url VARCHAR(500) NOT NULL,
    file_size INT DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT 'image/jpeg',
    source VARCHAR(50) DEFAULT 'img2img',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;