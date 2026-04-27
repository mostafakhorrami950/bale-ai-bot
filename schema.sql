-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bale_id BIGINT UNIQUE NOT NULL,
    first_name VARCHAR(255),
    last_name VARCHAR(255),
    username VARCHAR(255),
    phone_number VARCHAR(50),
    is_registered TINYINT(1) DEFAULT 0,
    credits DECIMAL(10, 2) DEFAULT 0.00,
    language_code VARCHAR(10) DEFAULT 'fa',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Required channels for membership gate
CREATE TABLE IF NOT EXISTS required_channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id BIGINT NOT NULL,
    title VARCHAR(255),
    invite_link VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI Models configuration
CREATE TABLE IF NOT EXISTS ai_models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    provider VARCHAR(50) DEFAULT 'gapgpt',
    cost_per_image INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI Requests tracking
CREATE TABLE IF NOT EXISTS ai_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    model_id INT NOT NULL,
    prompt TEXT,
    image_type ENUM('text2img', 'img2img') DEFAULT 'text2img',
    status ENUM('success', 'failed') DEFAULT 'success',
    reference_id VARCHAR(255) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert a default AI model
INSERT IGNORE INTO ai_models (id, name, provider, cost_per_image, is_active) 
VALUES (1, 'dall-e-3', 'gapgpt', 2, 1);

-- Credit ledger for transaction history
CREATE TABLE IF NOT EXISTS credit_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount INT NOT NULL,
    type ENUM('charge', 'deduction') NOT NULL,
    model_key VARCHAR(50),
    reference_id VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Production Hardening Tables
CREATE TABLE IF NOT EXISTS bot_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    context JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS processed_updates (
    update_id BIGINT PRIMARY KEY,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS webhook_health (
    id INT AUTO_INCREMENT PRIMARY KEY,
    last_received_at TIMESTAMP NULL,
    last_error TEXT,
    is_healthy TINYINT(1) DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Bot settings
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;