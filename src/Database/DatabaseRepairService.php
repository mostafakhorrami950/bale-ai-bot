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

        $this->ensureRequiredChannelsTable();
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
        $this->ensureChatConversationsTable();
        $this->ensureChatMessagesTable();
        $this->ensureNewModelTables();
        $this->ensureAiModelsCostColumns();
        $this->seedDefaultModel();
        $this->ensureUserProfilesTable();
        $this->ensureMemoryTables();
        $this->ensureUserMemorySettingsTable();
        $this->ensurePhase14Columns();
        $this->ensurePhase15Columns();
        $this->ensurePhase16VideoCapabilities();
        $this->ensureBroadcastJobsTable();
        $this->ensureBroadcastLogTable();
        $this->ensureVideoCostPerSecondColumn();
        $this->ensureChatMessagesModelNameColumn();
        $this->ensureChatMessagesActualCostColumn();
        $this->ensureImageModelCharCostColumns();
        $this->ensureAiRequestsFinanceColumns();
        $this->ensureBotTextsTable();

        return $this->messages;
    }

    // ———————————————————————————————————————————————————————
    // Table creation
    // ———————————————————————————————————————————————————————

    private function ensureRequiredChannelsTable(): void
    {
        if (!$this->tableExists('required_channels')) {
            $this->exec("
                CREATE TABLE required_channels (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    channel_id VARCHAR(100) NOT NULL,
                    title VARCHAR(255),
                    invite_link VARCHAR(255),
                    is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول required_channels ایجاد شد.');
        } else {
            // Ensure all columns exist — order matters for AFTER clause
            // Start with columns that don't reference other new columns
            if (!$this->columnExists('required_channels', 'channel_id')) {
                $this->exec("ALTER TABLE required_channels ADD COLUMN channel_id VARCHAR(100) NOT NULL AFTER id");
                $this->log('✅ ستون channel_id به جدول required_channels اضافه شد.');
            }
            if (!$this->columnExists('required_channels', 'title')) {
                $this->exec("ALTER TABLE required_channels ADD COLUMN title VARCHAR(255) DEFAULT NULL AFTER channel_id");
                $this->log('✅ ستون title به جدول required_channels اضافه شد.');
            }
            if (!$this->columnExists('required_channels', 'invite_link')) {
                // Use channel_id as reference for AFTER since title may not exist yet
                $after = $this->columnExists('required_channels', 'title') ? 'title' : 'channel_id';
                $this->exec("ALTER TABLE required_channels ADD COLUMN invite_link VARCHAR(255) NULL AFTER {$after}");
                $this->log('✅ ستون invite_link به جدول required_channels اضافه شد.');
            }
            if (!$this->columnExists('required_channels', 'is_active')) {
                $this->exec("ALTER TABLE required_channels ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER invite_link");
                $this->log('✅ ستون is_active به جدول required_channels اضافه شد.');
            }
        }
    }

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
        // Seed ai_image_models
        if ($this->tableExists('ai_image_models')) {
            try {
                $stmt = $this->conn->query("SELECT COUNT(*) as c FROM ai_image_models");
                $count = $stmt->fetch()['c'] ?? 0;
                if ($count == 0) {
                    $this->execIgnored("INSERT INTO ai_image_models (name, provider, cost_per_image, is_active) VALUES ('gpt-image-1', 'gapgpt', 2, 1)");
                    $this->execIgnored("INSERT INTO ai_image_models (name, provider, cost_per_image, is_active) VALUES ('gemini-3.1-flash-image-preview', 'gapgpt', 1, 1)");
                    $this->log('✅ مدل‌های پیش‌فرض تصویرساز درج شد.');
                }
            } catch (\Throwable $e) {}
        }
        // Seed ai_text_models
        if ($this->tableExists('ai_text_models')) {
            try {
                $stmt = $this->conn->query("SELECT COUNT(*) as c FROM ai_text_models");
                $count = $stmt->fetch()['c'] ?? 0;
                if ($count == 0) {
                    $this->execIgnored("INSERT INTO ai_text_models (name, provider, cost_per_input_char, cost_per_output_char, is_active) VALUES ('google/gemini-2.5-flash-image', 'openrouter', 0.000001, 0.000002, 1)");
                    $this->log('✅ مدل پیش‌فرض متنی درج شد.');
                }
            } catch (\Throwable $e) {}
        }
    }

    private function ensureMemoryTables(): void
    {
        // user_memories table
        if (!$this->tableExists('user_memories')) {
            $this->exec("
                CREATE TABLE user_memories (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT NOT NULL,
                    memory_text TEXT NOT NULL,
                    source_message TEXT NULL,
                    memory_type ENUM('explicit', 'extracted') DEFAULT 'explicit',
                    importance INT DEFAULT 1,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_memories (user_id, is_active),
                    INDEX idx_user_importance (user_id, importance DESC)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->log('✅ جدول user_memories ایجاد شد.');
        }
        // conversation_summaries table
        if (!$this->tableExists('conversation_summaries')) {
            $this->exec("
                CREATE TABLE conversation_summaries (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT NOT NULL,
                    summary_text TEXT NOT NULL,
                    message_count INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_summaries_user (user_id, created_at DESC)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->log('✅ جدول conversation_summaries ایجاد شد.');
        }
        // Memory module settings
        $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('memory_module_enabled', '1')");
        $this->log('✅ تنظیم memory_module_enabled اضافه شد.');
        $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('memory_summarization_model', '')");
        $this->log('✅ تنظیم memory_summarization_model اضافه شد.');
        $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('memory_extraction_model', '')");
        $this->log('✅ تنظیم memory_extraction_model اضافه شد.');
    }

    private function ensureUserMemorySettingsTable(): void
    {
        if (!$this->tableExists('user_memory_settings')) {
            $this->exec("
                CREATE TABLE user_memory_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL UNIQUE,
                    memory_disabled TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول user_memory_settings ایجاد شد.');
        }
    }

    private function ensureUserProfilesTable(): void
    {
        if (!$this->tableExists('user_profiles')) {
            $this->exec("
                CREATE TABLE user_profiles (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL UNIQUE,
                    first_name VARCHAR(100) DEFAULT NULL,
                    last_name VARCHAR(100) DEFAULT NULL,
                    username VARCHAR(100) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول user_profiles ایجاد شد.');
        } else {
            // Ensure username column exists
            if (!$this->columnExists('user_profiles', 'username')) {
                $this->exec("ALTER TABLE user_profiles ADD COLUMN username VARCHAR(100) DEFAULT NULL AFTER last_name");
                $this->log('✅ ستون username به جدول user_profiles اضافه شد.');
            }
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
            $safe = str_replace('`', '', $name);
            $stmt = $this->conn->query("SHOW TABLES LIKE '{$safe}'");
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $col): bool
    {
        try {
            $safeTable = str_replace('`', '', $table);
            $safeCol = str_replace('`', '', $col);
            $stmt = $this->conn->query("SHOW COLUMNS FROM `{$safeTable}` WHERE Field = '{$safeCol}'");
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function indexExists(string $table, string $idx): bool
    {
        try {
            $dbName = $this->conn->query("SELECT DATABASE() as db")->fetch()['db'];
            $safeTable = str_replace('`', '', $table);
            $safeIdx = str_replace('`', '', $idx);
            $stmt = $this->conn->query(
                "SELECT COUNT(*) as c FROM information_schema.STATISTICS 
                 WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$safeTable}' AND INDEX_NAME = '{$safeIdx}'"
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

    private function ensureChatConversationsTable(): void
    {
        if (!$this->tableExists('chat_conversations')) {
            $this->exec("
                CREATE TABLE chat_conversations (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول chat_conversations ایجاد شد.');
        }
    }

    private function ensureChatMessagesTable(): void
    {
        if (!$this->tableExists('chat_messages')) {
            $this->exec("
                CREATE TABLE chat_messages (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول chat_messages ایجاد شد.');
        }
    }

    private function ensureAiModelsCostColumns(): void
    {
        if (!$this->tableExists('ai_models')) return;
        if (!$this->columnExists('ai_models', 'cost_per_input_char')) {
            $this->exec("ALTER TABLE ai_models ADD COLUMN cost_per_input_char DECIMAL(10,6) DEFAULT 0.000001 AFTER cost_per_image");
            $this->log('✅ ستون cost_per_input_char به ai_models اضافه شد.');
        }
        if (!$this->columnExists('ai_models', 'cost_per_output_char')) {
            $this->exec("ALTER TABLE ai_models ADD COLUMN cost_per_output_char DECIMAL(10,6) DEFAULT 0.000002 AFTER cost_per_input_char");
            $this->log('✅ ستون cost_per_output_char به ai_models اضافه شد.');
        }
        if (!$this->columnExists('ai_models', 'free_model')) {
            $this->exec("ALTER TABLE ai_models ADD COLUMN free_model TINYINT(1) DEFAULT 0 AFTER is_active");
            $this->log('✅ ستون free_model به ai_models اضافه شد.');
        }
        if (!$this->columnExists('ai_models', 'model_type')) {
            $this->exec("ALTER TABLE ai_models ADD COLUMN model_type VARCHAR(30) DEFAULT 'image_generation' AFTER provider");
            $this->log('✅ ستون model_type به ai_models اضافه شد.');
        }
    }

    private function ensureNewModelTables(): void
    {
        // ai_image_models
        if (!$this->tableExists('ai_image_models')) {
            $this->exec("
                CREATE TABLE ai_image_models (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(200) NOT NULL,
                    provider VARCHAR(50) DEFAULT 'gapgpt',
                    cost_per_image INT NOT NULL DEFAULT 2,
                    model_config JSON DEFAULT NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول ai_image_models ایجاد شد.');
        }
        // ai_edit_models
        if (!$this->tableExists('ai_edit_models')) {
            $this->exec("
                CREATE TABLE ai_edit_models (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(200) NOT NULL,
                    provider VARCHAR(50) DEFAULT 'gapgpt',
                    cost_per_edit INT NOT NULL DEFAULT 2,
                    model_config JSON DEFAULT NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول ai_edit_models ایجاد شد.');
        }
        // ai_text_models
        if (!$this->tableExists('ai_text_models')) {
            $this->exec("
                CREATE TABLE ai_text_models (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول ai_text_models ایجاد شد.');
        }
        // ai_video_models
        if (!$this->tableExists('ai_video_models')) {
            $this->exec("
                CREATE TABLE ai_video_models (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(200) NOT NULL,
                    provider VARCHAR(50) DEFAULT 'gapgpt',
                    cost_per_video INT NOT NULL DEFAULT 5,
                    model_config JSON DEFAULT NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول ai_video_models ایجاد شد.');
        }
    }

    /**
     * Phase 14 columns: display_name, description, size, aspect_ratio.
     * Uses AFTER id for ai_edit_models since its cost column is cost_per_edit (not cost_per_image).
     */
    private function ensurePhase14Columns(): void
    {
        $tables = [
            'ai_image_models' => ['display_name' => 'name', 'description' => 'display_name', 'size' => 'cost_per_image', 'aspect_ratio' => 'size'],
            'ai_edit_models'  => ['display_name' => 'name', 'description' => 'display_name', 'size' => 'cost_per_edit', 'aspect_ratio' => 'size'],
            'ai_text_models'  => ['display_name' => 'name', 'description' => 'display_name'],
            'ai_video_models' => ['display_name' => 'name', 'description' => 'display_name'],
        ];

        foreach ($tables as $table => $cols) {
            if (!$this->tableExists($table)) continue;
            foreach ($cols as $col => $after) {
                if ($this->columnExists($table, $col)) continue;

                $type = 'VARCHAR(200) DEFAULT NULL';
                if ($col === 'description') $type = 'TEXT DEFAULT NULL';
                elseif ($col === 'size') $type = "VARCHAR(20) DEFAULT 'auto'";
                elseif ($col === 'aspect_ratio') $type = "VARCHAR(10) DEFAULT 'auto'";

                $this->exec("ALTER TABLE {$table} ADD COLUMN `{$col}` {$type} AFTER `{$after}`");
                $this->log("✅ ستون {$col} به جدول {$table} اضافه شد.");

                if ($col === 'display_name') {
                    $this->exec("UPDATE {$table} SET display_name = name WHERE display_name IS NULL");
                }
            }
        }
    }

    /**
     * Phase 15 columns: supported_formats, sort_order + decimal credits + help/default_text_model settings.
     */
    private function ensurePhase15Columns(): void
    {
        // supported_formats on ai_text_models
        if ($this->tableExists('ai_text_models') && !$this->columnExists('ai_text_models', 'supported_formats')) {
            $this->exec("ALTER TABLE ai_text_models ADD COLUMN supported_formats TEXT DEFAULT NULL AFTER free_model");
            $this->log('✅ ستون supported_formats به جدول ai_text_models اضافه شد.');
        }
        // sort_order on ai_text_models
        if ($this->tableExists('ai_text_models') && !$this->columnExists('ai_text_models', 'sort_order')) {
            $this->exec("ALTER TABLE ai_text_models ADD COLUMN sort_order INT DEFAULT 0 AFTER supported_formats");
            $this->log('✅ ستون sort_order به جدول ai_text_models اضافه شد.');
        }
        // Decimal credits on users (convert both int and decimal(12,4) to decimal(12,6))
        $this->ensureDecimalColumn('users', 'credits');
        // Decimal amount on credit_ledger
        $this->ensureDecimalColumn('credit_ledger', 'amount');
        // Decimal total_cost_credits on chat_conversations
        $this->ensureDecimalColumn('chat_conversations', 'total_cost_credits');
        // Decimal cost columns on chat_messages
        $this->ensureDecimalColumn('chat_messages', 'cost_input_credits');
        $this->ensureDecimalColumn('chat_messages', 'cost_output_credits');
        // default_text_model, help_text, help_image, chat_history_per_page settings
        $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('default_text_model', '')");
        $this->log('✅ تنظیم default_text_model اضافه شد.');
        $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('help_text', '')");
        $this->log('✅ تنظیم help_text اضافه شد.');
        $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('help_image', '')");
        $this->log('✅ تنظیم help_image اضافه شد.');
        $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('chat_history_per_page', '10')");
        $this->log('✅ تنظیم chat_history_per_page اضافه شد.');
        // Payment method settings
        $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('payment_method_zibal', 'on')");
        $this->log('✅ تنظیم payment_method_zibal اضافه شد.');
        $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('payment_method_bale', 'off')");
        $this->log('✅ تنظیم payment_method_bale اضافه شد.');
        $this->execIgnored("INSERT IGNORE INTO settings (key_name, value) VALUES ('bale_provider_token', '')");
        $this->log('✅ تنظیم bale_provider_token اضافه شد.');
        // Set default supported_formats
        if ($this->tableExists('ai_text_models') && $this->columnExists('ai_text_models', 'supported_formats')) {
            $this->exec("UPDATE ai_text_models SET supported_formats = 'txt,doc,pdf,jpg,jpeg,png,gif,webp' WHERE supported_formats IS NULL");
        }
    }

    /**
     * Ensure a column is DECIMAL(12,6). Handles both INT and DECIMAL(12,4) columns.
     */
    private function ensureDecimalColumn(string $table, string $column): void
    {
        if (!$this->tableExists($table) || !$this->columnExists($table, $column)) return;
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM {$table} WHERE Field = '{$column}'");
            $col = $stmt->fetch();
            $typeDef = $col['Type'] ?? '';
            // If it's INT, DECIMAL(12,4), or any type that can't hold 6 decimal places, convert it
            if (str_starts_with($typeDef, 'int') || str_starts_with($typeDef, 'decimal') && strpos($typeDef, '6') === false) {
                $this->exec("ALTER TABLE {$table} MODIFY COLUMN {$column} DECIMAL(12,6) NOT NULL DEFAULT 0");
                $this->log("✅ ستون {$column} در {$table} به DECIMAL(12,6) تغییر یافت.");
            }
        } catch (\Throwable $e) {}
    }

    private function ensurePhase16VideoCapabilities(): void
    {
        if (!$this->tableExists('ai_video_models')) return;

        $cols = [
            'display_name' => "VARCHAR(200) DEFAULT NULL AFTER name",
            'description' => "TEXT DEFAULT NULL AFTER display_name",
            'supported_resolutions' => "TEXT DEFAULT NULL AFTER description",
            'supported_sizes' => "TEXT DEFAULT NULL AFTER supported_resolutions",
            'supported_aspect_ratios' => "TEXT DEFAULT NULL AFTER supported_sizes",
            'supported_durations' => "TEXT DEFAULT NULL AFTER supported_aspect_ratios",
            'allow_first_frame' => "TINYINT(1) DEFAULT 0 AFTER supported_durations",
            'allow_last_frame' => "TINYINT(1) DEFAULT 0 AFTER allow_first_frame",
            'allow_input_references' => "TINYINT(1) DEFAULT 0 AFTER allow_last_frame",
            'allow_generate_audio' => "TINYINT(1) DEFAULT 1 AFTER allow_input_references",
            'allow_img2video' => "TINYINT(1) DEFAULT 0 AFTER allow_generate_audio",
            'pricing_json' => "JSON DEFAULT NULL AFTER cost_per_video",
        ];

        foreach ($cols as $col => $def) {
            if ($this->columnExists('ai_video_models', $col)) continue;
            $this->exec("ALTER TABLE ai_video_models ADD COLUMN `{$col}` {$def}");
            $this->log("✅ ستون {$col} به جدول ai_video_models اضافه شد.");
        }

        // Default values for existing rows
        $this->exec("UPDATE ai_video_models SET display_name = name WHERE display_name IS NULL");
        $this->exec("UPDATE ai_video_models SET supported_resolutions = '480p,720p,1080p' WHERE supported_resolutions IS NULL OR supported_resolutions = ''");
        $this->exec("UPDATE ai_video_models SET supported_sizes = '854x480,1280x720,1920x1080' WHERE supported_sizes IS NULL OR supported_sizes = ''");
        $this->exec("UPDATE ai_video_models SET supported_aspect_ratios = '16:9,9:16,1:1' WHERE supported_aspect_ratios IS NULL OR supported_aspect_ratios = ''");
        $this->exec("UPDATE ai_video_models SET supported_durations = '5,10,15' WHERE supported_durations IS NULL OR supported_durations = ''");
        $this->exec("UPDATE ai_video_models SET pricing_json = '{}' WHERE pricing_json IS NULL");

        $this->log('✅ قابلیت‌های مدل ویدئو (فاز ۱۶) بروزرسانی شد.');
    }

    private function ensureBroadcastJobsTable(): void
    {
        if (!$this->tableExists('broadcast_jobs')) {
            $this->exec("
                CREATE TABLE broadcast_jobs (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول broadcast_jobs ایجاد شد.');
        }
    }

    private function ensureBroadcastLogTable(): void
    {
        if (!$this->tableExists('broadcast_log')) {
            $this->exec("
                CREATE TABLE broadcast_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    job_id INT NOT NULL,
                    user_id INT NOT NULL,
                    status ENUM('sent','failed') DEFAULT 'sent',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_job_id (job_id),
                    INDEX idx_user_id (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول broadcast_log ایجاد شد.');
        }
    }

    private function ensureVideoCostPerSecondColumn(): void
    {
        if (!$this->tableExists('ai_video_models')) return;
        if (!$this->columnExists('ai_video_models', 'cost_per_second')) {
            $this->exec("ALTER TABLE ai_video_models ADD COLUMN cost_per_second INT DEFAULT 0 AFTER cost_per_video");
            $this->log('✅ ستون cost_per_second به جدول ai_video_models اضافه شد.');
        }
    }

    private function ensureChatMessagesModelNameColumn(): void
    {
        if (!$this->tableExists('chat_messages')) {
            $this->log('⚠️ جدول chat_messages وجود ندارد.');
            return;
        }
        // Always try to add — execIgnored ignores "Duplicate column" errors
        $this->execIgnored("ALTER TABLE chat_messages ADD COLUMN model_name VARCHAR(200) DEFAULT NULL AFTER file_content");
        if ($this->columnExists('chat_messages', 'model_name')) {
            $this->log('✅ ستون model_name در جدول chat_messages بررسی/اضافه شد.');
        } else {
            $this->log('⚠️ ستون model_name در جدول chat_messages اضافه نشد (ممکن است ستون file_content وجود نداشته باشد).');
            // Fallback: try adding without AFTER clause
            $this->execIgnored("ALTER TABLE chat_messages ADD COLUMN model_name VARCHAR(200) DEFAULT NULL");
            if ($this->columnExists('chat_messages', 'model_name')) {
                $this->log('✅ ستون model_name با روش جایگزین اضافه شد.');
            }
        }
    }

    private function ensureChatMessagesActualCostColumn(): void
    {
        if (!$this->tableExists('chat_messages')) {
            $this->log('⚠️ جدول chat_messages وجود ندارد.');
            return;
        }
        // actual_cost_usd
        $this->execIgnored("ALTER TABLE chat_messages ADD COLUMN actual_cost_usd DECIMAL(16,8) DEFAULT 0 AFTER model_name");
        if ($this->columnExists('chat_messages', 'actual_cost_usd')) {
            $this->log('✅ ستون actual_cost_usd در جدول chat_messages بررسی/اضافه شد.');
        } else {
            $this->execIgnored("ALTER TABLE chat_messages ADD COLUMN actual_cost_usd DECIMAL(16,8) DEFAULT 0");
            if ($this->columnExists('chat_messages', 'actual_cost_usd')) {
                $this->log('✅ ستون actual_cost_usd با روش جایگزین اضافه شد.');
            }
        }
        // input_tokens
        $this->execIgnored("ALTER TABLE chat_messages ADD COLUMN input_tokens INT DEFAULT 0 AFTER actual_cost_usd");
        if ($this->columnExists('chat_messages', 'input_tokens')) {
            $this->log('✅ ستون input_tokens در جدول chat_messages بررسی/اضافه شد.');
        } else {
            $this->execIgnored("ALTER TABLE chat_messages ADD COLUMN input_tokens INT DEFAULT 0");
            if ($this->columnExists('chat_messages', 'input_tokens')) {
                $this->log('✅ ستون input_tokens با روش جایگزین اضافه شد.');
            }
        }
        // output_tokens
        $this->execIgnored("ALTER TABLE chat_messages ADD COLUMN output_tokens INT DEFAULT 0 AFTER input_tokens");
        if ($this->columnExists('chat_messages', 'output_tokens')) {
            $this->log('✅ ستون output_tokens در جدول chat_messages بررسی/اضافه شد.');
        } else {
            $this->execIgnored("ALTER TABLE chat_messages ADD COLUMN output_tokens INT DEFAULT 0");
            if ($this->columnExists('chat_messages', 'output_tokens')) {
                $this->log('✅ ستون output_tokens با روش جایگزین اضافه شد.');
            }
        }
    }

    /**
     * Add cost_per_input_char and cost_per_output_char columns to ai_image_models and ai_edit_models.
     * These are used when image models return text instead of images (text fallback).
     */
    private function ensureImageModelCharCostColumns(): void
    {
        // ai_image_models: cost_per_input_char, cost_per_output_char
        if ($this->tableExists('ai_image_models')) {
            if (!$this->columnExists('ai_image_models', 'cost_per_input_char')) {
                $this->exec("ALTER TABLE ai_image_models ADD COLUMN cost_per_input_char DECIMAL(10,6) DEFAULT 0.000001 AFTER cost_per_image");
                $this->log('✅ ستون cost_per_input_char به ai_image_models اضافه شد.');
            }
            if (!$this->columnExists('ai_image_models', 'cost_per_output_char')) {
                $this->exec("ALTER TABLE ai_image_models ADD COLUMN cost_per_output_char DECIMAL(10,6) DEFAULT 0.000002 AFTER cost_per_input_char");
                $this->log('✅ ستون cost_per_output_char به ai_image_models اضافه شد.');
            }
        }
        // ai_edit_models: cost_per_input_char, cost_per_output_char
        if ($this->tableExists('ai_edit_models')) {
            if (!$this->columnExists('ai_edit_models', 'cost_per_input_char')) {
                $this->exec("ALTER TABLE ai_edit_models ADD COLUMN cost_per_input_char DECIMAL(10,6) DEFAULT 0.000001 AFTER cost_per_edit");
                $this->log('✅ ستون cost_per_input_char به ai_edit_models اضافه شد.');
            }
            if (!$this->columnExists('ai_edit_models', 'cost_per_output_char')) {
                $this->exec("ALTER TABLE ai_edit_models ADD COLUMN cost_per_output_char DECIMAL(10,6) DEFAULT 0.000002 AFTER cost_per_input_char");
                $this->log('✅ ستون cost_per_output_char به ai_edit_models اضافه شد.');
            }
        }
    }

    private function ensureBotTextsTable(): void
    {
        if (!$this->tableExists('bot_texts')) {
            $this->exec("
                CREATE TABLE bot_texts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    text_key VARCHAR(100) UNIQUE NOT NULL,
                    text_value TEXT NOT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->log('✅ جدول bot_texts ایجاد شد.');
        }
        // Seed default values (idempotent — INSERT IGNORE)
        try {
            \Core\BotTextService::seedDefaults();
            $this->log('✅ مقادیر پیش‌فرض متن‌ها در bot_texts درج شد.');
        } catch (\Throwable $e) {
            $this->log("⚠️ خطا در seed متن‌ها: " . $e->getMessage());
        }
    }

    private function ensureAiRequestsFinanceColumns(): void
    {
        if (!$this->tableExists('ai_requests')) {
            $this->log('⚠️ جدول ai_requests وجود ندارد.');
            return;
        }

        // model_name
        $this->execIgnored("ALTER TABLE ai_requests ADD COLUMN model_name VARCHAR(200) DEFAULT NULL AFTER model_id");
        if ($this->columnExists('ai_requests', 'model_name')) {
            $this->log('✅ ستون model_name به جدول ai_requests اضافه شد.');
        }

        // actual_cost_usd
        $this->execIgnored("ALTER TABLE ai_requests ADD COLUMN actual_cost_usd DECIMAL(16,8) DEFAULT 0 AFTER model_name");
        if ($this->columnExists('ai_requests', 'actual_cost_usd')) {
            $this->log('✅ ستون actual_cost_usd به جدول ai_requests اضافه شد.');
        }

        // input_chars
        $this->execIgnored("ALTER TABLE ai_requests ADD COLUMN input_chars INT DEFAULT 0 AFTER actual_cost_usd");
        if ($this->columnExists('ai_requests', 'input_chars')) {
            $this->log('✅ ستون input_chars به جدول ai_requests اضافه شد.');
        }

        // output_chars
        $this->execIgnored("ALTER TABLE ai_requests ADD COLUMN output_chars INT DEFAULT 0 AFTER input_chars");
        if ($this->columnExists('ai_requests', 'output_chars')) {
            $this->log('✅ ستون output_chars به جدول ai_requests اضافه شد.');
        }

        // cost_charged
        $this->execIgnored("ALTER TABLE ai_requests ADD COLUMN cost_charged DECIMAL(12,6) DEFAULT 0 AFTER output_chars");
        if ($this->columnExists('ai_requests', 'cost_charged')) {
            $this->log('✅ ستون cost_charged به جدول ai_requests اضافه شد.');
        }

        // Indexes
        if (!$this->indexExists('ai_requests', 'idx_model_name')) {
            $this->exec("CREATE INDEX idx_model_name ON ai_requests (model_name)");
            $this->log('✅ ایندکس idx_model_name روی ai_requests ایجاد شد.');
        }
        if (!$this->indexExists('ai_requests', 'idx_created_at')) {
            $this->exec("CREATE INDEX idx_created_at ON ai_requests (created_at)");
            $this->log('✅ ایندکس idx_created_at روی ai_requests ایجاد شد.');
        }
    }

    private function log(string $msg): void
    {
        $this->messages[] = $msg;
        Logger::info('DatabaseRepairService: ' . $msg);
    }
}