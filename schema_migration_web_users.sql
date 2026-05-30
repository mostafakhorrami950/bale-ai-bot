-- ==============================================================
-- Migration: Web Version Users Table
-- ==============================================================

CREATE TABLE IF NOT EXISTS web_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) UNIQUE NOT NULL COMMENT 'Mobile number with country code',
    name VARCHAR(100) DEFAULT NULL,
    bale_user_id BIGINT DEFAULT NULL COMMENT 'Linked Bale bot user ID',
    otp_code VARCHAR(6) DEFAULT NULL,
    otp_expires_at TIMESTAMP NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_phone (phone),
    INDEX idx_bale_user (bale_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;