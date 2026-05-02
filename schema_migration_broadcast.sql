-- Phase: Broadcast + Video cost per second + Memory state fix
-- Run this file to add broadcast tables and video model cost_per_second

-- 1. Broadcast jobs table (async queue)
CREATE TABLE IF NOT EXISTS broadcast_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT DEFAULT 0,
    message_text TEXT NOT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    total_users INT DEFAULT 0,
    sent_count INT DEFAULT 0,
    failed_count INT DEFAULT 0,
    status ENUM('pending','processing','completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Broadcast logs (per-user tracking)
CREATE TABLE IF NOT EXISTS broadcast_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('sent','failed') DEFAULT 'sent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_id (job_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Add cost_per_second to ai_video_models for per-second pricing
ALTER TABLE ai_video_models ADD COLUMN IF NOT EXISTS cost_per_second INT DEFAULT 0 AFTER cost_per_video;

-- 4. Add started_at column to broadcast_jobs (if not exists from CREATE)
-- Already included in CREATE above