-- ============================================================
-- PHASE 13: Separate model tables per type
-- ============================================================

-- 1. AI Text Models (for chat)
CREATE TABLE IF NOT EXISTS ai_text_models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    provider VARCHAR(50) DEFAULT 'openrouter',
    cost_per_input_char DECIMAL(10,6) DEFAULT 0.000001,
    cost_per_output_char DECIMAL(10,6) DEFAULT 0.000002,
    free_model TINYINT(1) DEFAULT 0,
    model_config JSON DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. AI Image Generation Models (for text2img)
CREATE TABLE IF NOT EXISTS ai_image_models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    provider VARCHAR(50) DEFAULT 'gapgpt',
    cost_per_image INT NOT NULL DEFAULT 2,
    model_config JSON DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. AI Image Editing Models (for img2img)
CREATE TABLE IF NOT EXISTS ai_edit_models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    provider VARCHAR(50) DEFAULT 'gapgpt',
    cost_per_edit INT NOT NULL DEFAULT 2,
    model_config JSON DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. AI Video Models (for video generation)
CREATE TABLE IF NOT EXISTS ai_video_models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    provider VARCHAR(50) DEFAULT 'gapgpt',
    cost_per_video INT NOT NULL DEFAULT 5,
    model_config JSON DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrate existing models from ai_models to new tables
INSERT IGNORE INTO ai_image_models (name, provider, cost_per_image, model_config, is_active)
SELECT name, provider, cost_per_image, model_config, is_active FROM ai_models 
WHERE model_type IS NULL OR model_type = 'image_generation';

INSERT IGNORE INTO ai_edit_models (name, provider, cost_per_image, model_config, is_active)
SELECT name, provider, cost_per_image, model_config, is_active FROM ai_models 
WHERE model_type = 'image_editing';

INSERT IGNORE INTO ai_text_models (name, provider, cost_per_input_char, cost_per_output_char, free_model, model_config, is_active)
SELECT name, provider, cost_per_input_char, cost_per_output_char, free_model, model_config, is_active FROM ai_models 
WHERE model_type = 'text';

INSERT IGNORE INTO ai_video_models (name, provider, cost_per_image, model_config, is_active)
SELECT name, provider, cost_per_image, model_config, is_active FROM ai_models 
WHERE model_type = 'video';

-- Insert default image models if no image models exist
INSERT IGNORE INTO ai_image_models (id, name, provider, cost_per_image, is_active) VALUES (1, 'gpt-image-1', 'gapgpt', 2, 1);
INSERT IGNORE INTO ai_image_models (id, name, provider, cost_per_image, is_active) VALUES (2, 'gemini-3.1-flash-image-preview', 'gapgpt', 1, 1);

-- Insert default text model
INSERT IGNORE INTO ai_text_models (id, name, provider, cost_per_input_char, cost_per_output_char, is_active) VALUES (1, 'google/gemini-2.5-flash-image', 'openrouter', 0.000001, 0.000002, 1);