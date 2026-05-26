-- ==============================================================
-- Migration: app_errors table for critical bot error logging
-- ==============================================================

CREATE TABLE IF NOT EXISTS app_errors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    error_type VARCHAR(100) NOT NULL DEFAULT 'unknown',
    error_message TEXT NOT NULL,
    error_trace TEXT DEFAULT NULL,
    bale_user_id INT DEFAULT NULL,
    payload_data TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_error_type (error_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add app_errors table to repair service detection