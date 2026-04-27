<?php

namespace Database;

class Bootstrap
{
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function run()
    {
        $this->createAdminsTable();
        $this->createUsersTable();
        $this->createUserProfilesTable();
        $this->createBotStateTable();
        $this->createSettingsTable();
        $this->createChannelsTable();
        $this->createModelsTable();
        $this->createCreditLedgerTable();
        $this->createPaymentsTable();
        $this->createDailyUsageTable();
        $this->createSystemLogsTable();
        $this->createApiKeysTable();
    }

    private function createAdminsTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->db->query($sql);
    }

    private function createUsersTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bale_user_id BIGINT UNIQUE NOT NULL,
            phone_number VARCHAR(20) DEFAULT NULL,
            is_registered TINYINT(1) DEFAULT 0,
            credits DECIMAL(10, 2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->db->query($sql);
    }

    private function createUserProfilesTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS user_profiles (
            user_id INT PRIMARY KEY,
            first_name VARCHAR(100),
            last_name VARCHAR(100),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->db->query($sql);
    }

    private function createBotStateTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS bot_state (
            user_id INT PRIMARY KEY,
            state VARCHAR(50) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->db->query($sql);
    }

    private function createSettingsTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            key_name VARCHAR(100) UNIQUE NOT NULL,
            value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->db->query($sql);
    }

    private function createChannelsTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS required_channels (
            id INT AUTO_INCREMENT PRIMARY KEY,
            channel_username VARCHAR(100) NOT NULL,
            channel_link VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->db->query($sql);
    }

    private function createModelsTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS ai_models (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            provider VARCHAR(50) DEFAULT 'gapgpt',
            cost_per_image INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->db->query($sql);
    }

    private function createCreditLedgerTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS credit_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            type ENUM('credit', 'debit') NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->db->query($sql);
    }

    private function createPaymentsTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            authority VARCHAR(100) DEFAULT NULL,
            status VARCHAR(50) DEFAULT 'pending',
            ref_id VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->db->query($sql);
    }

    private function createDailyUsageTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS daily_usage (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            usage_date DATE NOT NULL,
            request_count INT DEFAULT 0,
            UNIQUE KEY (user_id, usage_date),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->db->query($sql);
    }

    private function createSystemLogsTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS system_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            level VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->db->query($sql);
    }

    private function createApiKeysTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS api_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(50) DEFAULT 'gapgpt',
            api_key TEXT NOT NULL,
            is_active TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->db->query($sql);
    }
}