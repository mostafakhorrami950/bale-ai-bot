<?php

namespace Database;

class DatabaseRepairService
{
    private $db;
    private $conn;
    private $messages = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->conn = $this->db->getConnection();
    }

    /**
     * Run all checks and repairs. Returns array of messages.
     */
    public function repairAll(): array
    {
        $this->messages = [];

        $this->ensurePaymentPlansTable();
        $this->ensurePaymentsTable();
        $this->ensurePaymentLogsTable();
        $this->ensureAdminActionsTable();
        $this->ensureSettingsTable();
        $this->ensureBotLogsTable();
        $this->ensureAiRequestsTable();
        $this->ensureCreditLedgerIndex();
        $this->ensureAiModelsProviderColumn();
        $this->ensureApiKeysProviderColumn();
        $this->ensureUsersColumns();
        $this->ensureBotStateExtraDataColumn();
        $this->ensureAiModelsModelConfigColumn();
        $this->ensureUploadedFilesTable();
        $this->seedDefaultModel();

        return $this->messages;
    }

    // ———————————————————————————————————————————————————————
    // Table creation
    // ———————————————————————————————————————————————————————

    private function ensurePaymentPlansTable(): void
    {
        if (!$this->tableExists('payment_plans')) {
            $this->exec("
                CREATE TABLE payment_plans (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    plan_id VARCHAR(50) UNIQUE NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    credits INT NOT NULL,
                    price_rial INT NOT NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            // Insert defaults
            $this->execIgnored("INSERT IGNORE INTO payment_plans (plan_id, name, credits, price_rial) VALUES ('basic', 'پایه', 50, 49000)");
            $this->execIgnored("INSERT IGNORE INTO payment_plans (plan_id, name, credits, price_rial) VALUES ('standard', 'استاندارد', 150, 139000)");
            $this->execIgnored("INSERT IGNORE INTO payment_plans (plan_id, name, credits, price_rial) VALUES ('premium', 'حرفه‌ای', 500, 449000)");
            $this->log('✅ جدول payment_plans ایجاد شد.');
        }
    }

    private function ensurePaymentsTable(): void
    {
        if (!$this->tableExists('payments')) {
            $this->exec("
                CREATE TABLE payments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT NOT NULL,
                    track_id VARCHAR(100) UNIQUE NOT NULL,
                    order_id VARCHAR(100) NULL,
                    amount_rial INT NOT NULL,
                    credits INT NOT NULL,
                    plan_id VARCHAR(50) NULL,
                    status ENUM('pending','verified','failed') DEFAULT 'pending',
                    ref_number VARCHAR(100) NULL,
                    verified_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_track_id (track_id),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول payments ایجاد شد.');
        } else {
            // Check and add ALL required columns
            $requiredColumns = [
                'amount_rial'  => "ALTER TABLE payments ADD COLUMN amount_rial INT NOT NULL AFTER track_id",
                'track_id'     => "ALTER TABLE payments ADD COLUMN track_id VARCHAR(100) UNIQUE NOT NULL AFTER user_id",
                'credits'      => "ALTER TABLE payments ADD COLUMN credits INT NOT NULL DEFAULT 0 AFTER amount_rial",
                'ref_number'   => "ALTER TABLE payments ADD COLUMN ref_number VARCHAR(100) NULL AFTER status",
                'verified_at'  => "ALTER TABLE payments ADD COLUMN verified_at TIMESTAMP NULL AFTER ref_number",
                'order_id'     => "ALTER TABLE payments ADD COLUMN order_id VARCHAR(100) NULL AFTER plan_id",
                'plan_id'      => "ALTER TABLE payments ADD COLUMN plan_id VARCHAR(50) NULL AFTER credits",
            ];
            foreach ($requiredColumns as $col => $alterSql) {
                if (!$this->columnExists('payments', $col)) {
                    $this->exec($alterSql);
                    $this->log("✅ ستون {$col} به جدول payments اضافه شد.");
                }
            }

            // Ensure indexes exist
            if (!$this->indexExists('payments', 'idx_track_id')) {
                $this->exec("CREATE INDEX idx_track_id ON payments (track_id)");
                $this->log('✅ ایندکس idx_track_id روی payments ایجاد شد.');
            }
            if (!$this->indexExists('payments', 'idx_status')) {
                $this->exec("CREATE INDEX idx_status ON payments (status)");
                $this->log('✅ ایندکس idx_status روی payments ایجاد شد.');
            }
        }
    }

    private function ensurePaymentLogsTable(): void
    {
        if (!$this->tableExists('payment_logs')) {
            $this->exec("
                CREATE TABLE payment_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    track_id VARCHAR(100) NULL,
                    action VARCHAR(50) NOT NULL,
                    request_data TEXT NULL,
                    response_data TEXT NULL,
                    status VARCHAR(20) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول payment_logs ایجاد شد.');
        }
    }

    private function ensureAdminActionsTable(): void
    {
        if (!$this->tableExists('admin_actions')) {
            $this->exec("
                CREATE TABLE admin_actions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_username VARCHAR(100) NOT NULL,
                    action VARCHAR(100) NOT NULL,
                    target_type VARCHAR(50) NULL,
                    target_id INT NULL,
                    details TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_admin (admin_username),
                    INDEX idx_action (action),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول admin_actions ایجاد شد.');
        }
    }

    private function ensureSettingsTable(): void
    {
        if (!$this->tableExists('settings')) {
            $this->exec("
                CREATE TABLE settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    key_name VARCHAR(100) UNIQUE NOT NULL,
                    value TEXT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('required_channel_id', '@mobix_tube')");
            $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('required_channel_link', 'https://t.me/mobix_tube')");
            $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('free_daily_limit', '1')");
            $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('initial_credit', '15')");
            $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('welcome_message', 'به ربات هوش مصنوعی خوش آمدید!')");
            $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('maintenance_mode', 'off')");
            $this->log('✅ جدول settings ایجاد شد.');
        }
    }

    private function ensureBotLogsTable(): void
    {
        if (!$this->tableExists('bot_logs')) {
            $this->exec("
                CREATE TABLE IF NOT EXISTS bot_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    level VARCHAR(20) NOT NULL,
                    message TEXT NOT NULL,
                    context JSON DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول bot_logs ایجاد شد.');
        }
    }

    private function ensureAiRequestsTable(): void
    {
        if (!$this->tableExists('ai_requests')) {
            $this->exec("
                CREATE TABLE IF NOT EXISTS ai_requests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT NOT NULL,
                    model_id INT NOT NULL,
                    prompt TEXT,
                    image_type ENUM('text2img','img2img') DEFAULT 'text2img',
                    status ENUM('success','failed') DEFAULT 'success',
                    reference_id VARCHAR(255) UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول ai_requests ایجاد شد.');
        }
    }

    // ———————————————————————————————————————————————————————
    // Column/Index ALTERs
    // ———————————————————————————————————————————————————————

    private function ensureCreditLedgerIndex(): void
    {
        if ($this->tableExists('credit_ledger')) {
            // First check if reference_id already has a UNIQUE index (skip if so)
            $hasUnique = $this->indexExists('credit_ledger', 'idx_reference_id_unique');
            
            // First ensure reference_id column exists — use AFTER created_at for safety
            // Bootstrap creates credit_ledger without model_key, so AFTER model_key would fail
            if (!$this->columnExists('credit_ledger', 'reference_id')) {
                $this->exec("ALTER TABLE credit_ledger ADD COLUMN reference_id VARCHAR(100) NULL");
                $this->log('✅ ستون reference_id به جدول credit_ledger اضافه شد.');
            }
            // Also ensure model_key column exists (needed by CreditService)
            if (!$this->columnExists('credit_ledger', 'model_key')) {
                $this->exec("ALTER TABLE credit_ledger ADD COLUMN model_key VARCHAR(50) NULL AFTER type");
                $this->log('✅ ستون model_key به جدول credit_ledger اضافه شد.');
            }
            // Then create index
            if (!$this->indexExists('credit_ledger', 'idx_reference_id')) {
                $this->exec("CREATE INDEX idx_reference_id ON credit_ledger (reference_id)");
                $this->log('✅ ایندکس idx_reference_id روی credit_ledger ایجاد شد.');
            }
            // N6: Create UNIQUE index for idempotency
            if (!$hasUnique) {
                // First drop any existing regular index with same name to avoid conflict
                if ($this->indexExists('credit_ledger', 'idx_reference_id_unique')) {
                    $this->exec("DROP INDEX idx_reference_id_unique ON credit_ledger");
                }
                $this->exec("ALTER TABLE credit_ledger ADD UNIQUE INDEX idx_reference_id_unique (reference_id)");
                $this->log('✅ ایندکس یکتا idx_reference_id_unique روی credit_ledger ایجاد شد.');
            }
            // Fix ENUM type to support 'charge' and 'deduction' values used by CreditService
            $this->fixCreditLedgerTypeEnum();
        }
    }

    /**
     * Fix the credit_ledger.type ENUM to include 'charge' and 'deduction' values.
     * Bootstrap creates ENUM('credit','debit') but CreditService uses 'charge' and 'deduction'.
     */
    private function fixCreditLedgerTypeEnum(): void
    {
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM credit_ledger WHERE Field = 'type'");
            $col = $stmt->fetch();
            if ($col) {
                $typeDef = $col['Type'] ?? '';
                // If type is enum('credit','debit'), alter it
                if (strpos($typeDef, "enum('credit','debit')") !== false) {
                    $this->exec("ALTER TABLE credit_ledger MODIFY COLUMN type VARCHAR(20) NOT NULL DEFAULT 'charge'");
                    $this->log('✅ ستون type در credit_ledger به VARCHAR(20) تغییر یافت.');
                }
            }
        } catch (\Throwable $e) {
            $this->log("⚠️ خطا در fixCreditLedgerTypeEnum: " . $e->getMessage());
        }
    }

    private function ensureAiModelsProviderColumn(): void
    {
        if ($this->tableExists('ai_models') && !$this->columnExists('ai_models', 'provider')) {
            $this->exec("ALTER TABLE ai_models ADD COLUMN provider VARCHAR(50) DEFAULT 'gapgpt' AFTER name");
            $this->log('✅ ستون provider به جدول ai_models اضافه شد.');
        }
    }

    private function ensureApiKeysProviderColumn(): void
    {
        if ($this->tableExists('api_keys') && !$this->columnExists('api_keys', 'provider')) {
            $this->exec("ALTER TABLE api_keys ADD COLUMN provider VARCHAR(50) DEFAULT 'gapgpt' AFTER id");
            $this->log('✅ ستون provider به جدول api_keys اضافه شد.');
        }
    }

    private function ensureBotStateExtraDataColumn(): void
    {
        if ($this->tableExists('bot_state') && !$this->columnExists('bot_state', 'extra_data')) {
            $this->exec("ALTER TABLE bot_state ADD COLUMN extra_data TEXT NULL AFTER state");
            $this->log('✅ ستون extra_data به جدول bot_state اضافه شد.');
        }
    }

    private function seedDefaultModel(): void
    {
        if (!$this->tableExists('ai_models')) return;
        try {
            $stmt = $this->conn->query("SELECT COUNT(*) as c FROM ai_models");
            $count = $stmt->fetch()['c'] ?? 0;
            if ($count == 0) {
                $this->exec("
                    INSERT IGNORE INTO ai_models (name, provider, cost_per_image, is_active) VALUES 
                    ('gpt-image-1', 'gapgpt', 2, 1),
                    ('gemini-3-pro-image-preview', 'gapgpt', 3, 1),
                    ('gemini-3.1-flash-image-preview', 'gapgpt', 1, 1)
                ");
                $this->log('✅ سه مدل پیش‌فرض AI در ai_models درج شد.');
            }
        } catch (\Throwable $e) {
            $this->log("⚠️ خطا در seedDefaultModel: " . $e->getMessage());
        }
    }

    private function ensureUsersColumns(): void
    {
        if (!$this->tableExists('users')) return;

        if (!$this->columnExists('users', 'phone_number')) {
            $this->exec("ALTER TABLE users ADD COLUMN phone_number VARCHAR(20) NULL AFTER username");
            $this->log('✅ ستون phone_number به جدول users اضافه شد.');
        }
        if (!$this->columnExists('users', 'credits')) {
            $this->exec("ALTER TABLE users ADD COLUMN credits INT DEFAULT 0 AFTER phone_number");
            $this->log('✅ ستون credits به جدول users اضافه شد.');
        }
        if (!$this->columnExists('users', 'last_active_at')) {
            // Check if updated_at exists for positioning
            $afterCol = $this->columnExists('users', 'updated_at') ? 'updated_at' : 'created_at';
            $this->exec("ALTER TABLE users ADD COLUMN last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER {$afterCol}");
            $this->log('✅ ستون last_active_at به جدول users اضافه شد.');
        }
    }

    // ———————————————————————————————————————————————————————
    // Helpers
    // ———————————————————————————————————————————————————————

    private function tableExists(string $name): bool
    {
        try {
            $stmt = $this->conn->query("SHOW TABLES LIKE '{$name}'");
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $col): bool
    {
        try {
            // Use WHERE Field = instead of LIKE to avoid underscore wildcard issues
            $stmt = $this->conn->query("SHOW COLUMNS FROM {$table} WHERE Field = '{$col}'");
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function indexExists(string $table, string $idx): bool
    {
        try {
            $dbName = $this->conn->query("SELECT DATABASE() as db")->fetch()['db'];
            $stmt = $this->conn->query(
                "SELECT COUNT(*) as c FROM information_schema.STATISTICS 
                 WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table}' AND INDEX_NAME = '{$idx}'"
            );
            $row = $stmt->fetch();
            return $row && $row['c'] > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function exec(string $sql): void
    {
        try {
            $this->conn->exec($sql);
        } catch (\Throwable $e) {
            $this->log("⚠️ خطا: " . $e->getMessage());
        }
    }

    private function execIgnored(string $sql): void
    {
        try {
            $this->conn->exec($sql);
        } catch (\Throwable $e) {
            // Ignore duplicate key errors
        }
    }

    private function ensureAiModelsModelConfigColumn(): void
    {
        if ($this->tableExists('ai_models') && !$this->columnExists('ai_models', 'model_config')) {
            $this->exec("ALTER TABLE ai_models ADD COLUMN model_config JSON NULL AFTER cost_per_image");
            $this->log('✅ ستون model_config به جدول ai_models اضافه شد.');
        }
    }

    private function ensureUploadedFilesTable(): void
    {
        if (!$this->tableExists('uploaded_files')) {
            $this->exec("
                CREATE TABLE uploaded_files (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول uploaded_files ایجاد شد.');
        }
    }

    private function log(string $msg): void
    {
        $this->messages[] = $msg;
        Logger::info('DatabaseRepairService: ' . $msg);
    }
}