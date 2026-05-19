-- Table for tracking AI-generated files (images/videos) for retrieval by Generation ID
CREATE TABLE IF NOT EXISTS generated_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    generation_id VARCHAR(128) NOT NULL COMMENT 'OpenRouter generation ID or custom reference',
    model_name VARCHAR(255) NOT NULL,
    prompt TEXT,
    file_type VARCHAR(32) NOT NULL COMMENT 'image, video',
    media_type VARCHAR(32) NOT NULL COMMENT 'text2img, img2img, video, text2video',
    file_path VARCHAR(512) NOT NULL COMMENT 'Path to locally stored file',
    file_size INT DEFAULT 0,
    mime_type VARCHAR(64) DEFAULT '',
    stored_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_generation_id (generation_id),
    INDEX idx_file_type (file_type),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;