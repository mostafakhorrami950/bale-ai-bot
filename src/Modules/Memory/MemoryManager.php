<?php

namespace Modules\Memory;

use Database\Database;
use Database\Logger;
use Modules\AI\ChatService;

class MemoryManager
{
    private Database $db;
    private ?object $aiService;
    private array $config;

    public function __construct(?object $aiService = null, array $config = [])
    {
        $this->db = Database::getInstance();
        $this->aiService = $aiService;
        $this->config = $config;

        // Load summarization model from settings if not provided in config
        if (empty($this->config['summarization_model'])) {
            try {
                $row = $this->db->query(
                    "SELECT value FROM settings WHERE key_name = 'memory_summarization_model'"
                )->fetch();
                if ($row && !empty($row['value'])) {
                    $this->config['summarization_model'] = $row['value'];
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Load extraction model from settings if not provided in config
        if (empty($this->config['extraction_model'])) {
            try {
                $row = $this->db->query(
                    "SELECT value FROM settings WHERE key_name = 'memory_extraction_model'"
                )->fetch();
                if ($row && !empty($row['value'])) {
                    $this->config['extraction_model'] = $row['value'];
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * Check if the memory module is enabled via admin settings.
     */
    public function isEnabled(): bool
    {
        try {
            $rows = $this->db->query(
                "SELECT value FROM settings WHERE key_name = ?",
                ['memory_module_enabled']
            );
            $row = $rows ? $rows->fetch() : null;
            return $row && (string)$row['value'] === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get config value with fallback.
     */
    public function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Get all active memories for a user.
     */
    public function getUserMemories(int $userId, int $limit = 10): array
    {
        if (!$this->isEnabled()) return [];

        try {
            return $this->db->query(
                "SELECT id, memory_text, memory_type, importance, created_at 
                 FROM user_memories 
                 WHERE user_id = ? AND is_active = 1 
                 ORDER BY importance DESC, created_at DESC 
                 LIMIT ?",
                [$userId, $limit]
            )->fetchAll();
        } catch (\Throwable $e) {
            Logger::error('MemoryManager::getUserMemories error', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get recent conversation summaries for a user.
     */
    public function getRecentSummaries(int $userId, int $limit = 3): array
    {
        if (!$this->isEnabled()) return [];

        try {
            return $this->db->query(
                "SELECT summary_text, created_at 
                 FROM conversation_summaries 
                 WHERE user_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT ?",
                [$userId, $limit]
            )->fetchAll();
        } catch (\Throwable $e) {
            Logger::error('MemoryManager::getRecentSummaries error', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Add a new memory for a user.
     */
    public function addMemory(int $userId, string $memoryText, string $type = 'explicit', ?string $sourceMessage = null, int $importance = 1): void
    {
        if (!$this->isEnabled()) return;

        try {
            // Check for duplicates using similarity
            $existingMemories = $this->db->query(
                "SELECT id, memory_text FROM user_memories WHERE user_id = ? AND is_active = 1",
                [$userId]
            )->fetchAll();

            foreach ($existingMemories as $mem) {
                similar_text(mb_strtolower($memoryText), mb_strtolower($mem['memory_text']), $percent);
                if ($percent > 80) {
                    // Update timestamp to keep it fresh
                    $this->db->query(
                        "UPDATE user_memories SET updated_at = NOW() WHERE id = ?",
                        [$mem['id']]
                    );
                    return;
                }
            }

            $this->db->query(
                "INSERT INTO user_memories (user_id, memory_text, source_message, memory_type, importance) 
                 VALUES (?, ?, ?, ?, ?)",
                [$userId, $memoryText, $sourceMessage, $type, $importance]
            );

            Logger::info('MemoryManager::addMemory', [
                'user_id' => $userId,
                'type' => $type,
                'importance' => $importance,
                'text_preview' => mb_substr($memoryText, 0, 100),
            ]);
        } catch (\Throwable $e) {
            Logger::error('MemoryManager::addMemory error', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Build memory context string to inject into system prompt.
     * If there are >30 items and total chars >300, summarize with AI first.
     */
    public function buildMemoryContext(int $userId): string
    {
        if (!$this->isEnabled()) return '';

        $parts = [];

        // Get all active memories (up to 50 for summarization consideration)
        $allMemories = $this->getAllActiveMemories($userId, 50);

        if (!empty($allMemories)) {
            // Calculate total character count
            $totalChars = 0;
            $memoryLines = [];
            foreach ($allMemories as $mem) {
                $line = '• ' . $mem['memory_text'];
                $memoryLines[] = $line;
                $totalChars += mb_strlen($line);
            }

            // If >30 items AND total chars >300, summarize with AI
            if (count($allMemories) > 30 && $totalChars > 300) {
                $summary = $this->summarizeMemoriesWithAI($userId, $memoryLines);
                if (!empty($summary)) {
                    $parts[] = "📝 اطلاعات خلاصه شده درباره کاربر:\n" . $summary;
                } else {
                    // Fallback: use top 10 most important
                    $topMemories = $this->getUserMemories($userId, 10);
                    $topLines = [];
                    foreach ($topMemories as $mem) {
                        $topLines[] = '• ' . $mem['memory_text'];
                    }
                    $parts[] = "📝 اطلاعات مهم درباره کاربر:\n" . implode("\n", $topLines);
                }
            } else {
                // Few items — show all directly
                $parts[] = "📝 اطلاعات ذخیره شده درباره کاربر:\n" . implode("\n", $memoryLines);
            }
        }

        // Add recent summaries
        $summaries = $this->getRecentSummaries($userId);
        if (!empty($summaries)) {
            $summaryLines = [];
            foreach ($summaries as $sum) {
                $summaryLines[] = '• ' . mb_substr($sum['summary_text'], 0, 200);
            }
            $parts[] = "📋 خلاصه گفتگوهای قبلی:\n" . implode("\n", $summaryLines);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Get all active memories for a user (unlimited).
     */
    private function getAllActiveMemories(int $userId, int $limit = 50): array
    {
        try {
            return $this->db->query(
                "SELECT id, memory_text, memory_type, importance, created_at 
                 FROM user_memories 
                 WHERE user_id = ? AND is_active = 1 
                 ORDER BY importance DESC, created_at DESC 
                 LIMIT ?",
                [$userId, $limit]
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Summarize a large list of memories using the configured AI model.
     * Returns a compact Persian summary string.
     */
    private function summarizeMemoriesWithAI(int $userId, array $memoryLines): ?string
    {
        try {
            // Get the summarization model from settings
            $sumModelId = $this->getConfig('summarization_model', '');
            $modelName = '';
            $modelData = [];

            if (!empty($sumModelId)) {
                $modelRow = $this->db->query(
                    "SELECT name, provider, cost_per_input_char, cost_per_output_char, free_model 
                     FROM ai_text_models WHERE id = ? AND is_active = 1",
                    [(int)$sumModelId]
                )->fetch();
                if ($modelRow) {
                    $modelName = $modelRow['name'];
                    $modelData = [
                        'name' => $modelName,
                        'provider' => $modelRow['provider'] ?? 'openrouter',
                        'cost_per_input_char' => (float)($modelRow['cost_per_input_char'] ?? 0.000001),
                        'cost_per_output_char' => (float)($modelRow['cost_per_output_char'] ?? 0.000002),
                        'free_model' => (int)($modelRow['free_model'] ?? 0),
                    ];
                }
            }

            if (empty($modelName)) {
                // Fallback: use first active text model
                $modelRow = $this->db->query(
                    "SELECT name, provider, cost_per_input_char, cost_per_output_char, free_model 
                     FROM ai_text_models WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
                )->fetch();
                if ($modelRow) {
                    $modelName = $modelRow['name'];
                    $modelData = [
                        'name' => $modelName,
                        'provider' => $modelRow['provider'] ?? 'openrouter',
                        'cost_per_input_char' => (float)($modelRow['cost_per_input_char'] ?? 0.000001),
                        'cost_per_output_char' => (float)($modelRow['cost_per_output_char'] ?? 0.000002),
                        'free_model' => (int)($modelRow['free_model'] ?? 0),
                    ];
                }
            }

            if (empty($modelName)) return null;

            $systemPrompt = "تو یک دستیار خلاصه‌ساز اطلاعات شخصی هستی.\n"
                . "لیستی از اطلاعات ذخیره شده درباره یک کاربر به تو داده می‌شود.\n"
                . "وظیفه تو: این اطلاعات را به صورت خلاصه و منظم به فارسی بنویس.\n"
                . "قوانین:\n"
                . "1. فقط اطلاعات مهم را نگه دار\n"
                . "2. موارد تکراری را حذف کن\n"
                . "3. خروجی باید حداکثر 300 کاراکتر باشد\n"
                . "4. اطلاعات مشابه را ادغام کن\n"
                . "5. از خط تیره برای بولت‌پوینت استفاده کن\n"
                . "6. خروجی فقط متن فارسی باشد (بدون JSON)";

            $userText = "لیست اطلاعات کاربر:\n" . implode("\n", $memoryLines);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userText],
            ];

            $chatService = new ChatService();
            $result = $chatService->chat($messages, $modelName, $modelData);

            if (!isset($result['error']) && !empty($result['response'])) {
                return trim($result['response']);
            }

            Logger::error('MemoryManager::summarizeMemoriesWithAI error', [
                'user_id' => $userId,
                'error' => $result['error'] ?? 'empty response',
            ]);
            return null;

        } catch (\Throwable $e) {
            Logger::error('MemoryManager::summarizeMemoriesWithAI exception', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ———— Auto-extraction with AI ————

    /**
     * Auto-extract important personal information using AI.
     * Only called once per conversation (on the first user message).
     */
    public function extractMemoryFromMessage(int $userId, string $userMessage): void
    {
        if (!$this->isEnabled()) return;

        // If user has personally disabled memory, skip extraction
        if ($this->isDisabledForUser($userId)) return;

        // Skip very short messages
        if (mb_strlen(trim($userMessage)) < 10) return;

        try {
            // Get the default text model
            $modelName = $this->getConfig('extraction_model', '');
            if (empty($modelName)) {
                $row = $this->db->query(
                    "SELECT value FROM settings WHERE key_name = 'default_text_model'"
                )->fetch();
                if ($row && !empty($row['value'])) {
                    $modelRow = $this->db->query(
                        "SELECT name FROM ai_text_models WHERE id = ? AND is_active = 1",
                        [(int)$row['value']]
                    )->fetch();
                    if ($modelRow) $modelName = $modelRow['name'];
                }
            }
            if (empty($modelName)) {
                $modelRow = $this->db->query(
                    "SELECT name FROM ai_text_models WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
                )->fetch();
                if ($modelRow) $modelName = $modelRow['name'];
            }
            if (empty($modelName)) return;

            $systemPrompt = "تو یک دستیار هوشمند هستی که وظیفه‌ات استخراج اطلاعات شخصی مهم از پیام‌های کاربران است.\n\n"
                . "قوانین:\n"
                . "1. فقط اطلاعات شخصی مهم را استخراج کن (نام، سن، شغل، علایق، آدرس، شماره تماس، وضعیت سلامتی، اهداف، تاریخ‌های مهم و ...)\n"
                . "2. اگر اطلاعات مهمی در پیام وجود ندارد، خالی برگردان\n"
                . "3. خروجی را به صورت JSON برگردان با این ساختار:\n"
                . "{\"has_important_info\": true/false, \"memories\": [{\"text\": \"متن حافظه به فارسی\", \"importance\": 1-10}]}\n"
                . "4. importance از 1 (کم اهمیت) تا 10 (بسیار مهم) باشد\n"
                . "5. نام = 10, سن = 8, شغل = 8, شماره تماس = 9, آدرس = 8, وضعیت سلامتی = 7, اهداف = 6, علایق = 5\n"
                . "6. حتماً خروجی JSON معتبر برگردان";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "پیام کاربر:\n" . $userMessage],
            ];

            $chatService = new ChatService();
            $modelData = $this->getModelData($modelName);
            $result = $chatService->chat($messages, $modelName, $modelData);

            if (isset($result['error'])) {
                Logger::error('MemoryManager::extractMemoryFromMessage AI error', ['user_id' => $userId, 'error' => $result['error']]);
                return;
            }

            $responseText = $result['response'] ?? '';
            if (empty($responseText)) return;

            $json = $this->extractJsonFromResponse($responseText);
            if ($json === null) {
                Logger::error('MemoryManager::extractMemoryFromMessage parse error', ['user_id' => $userId, 'response' => mb_substr($responseText, 0, 500)]);
                return;
            }

            if (!isset($json['has_important_info']) || $json['has_important_info'] !== true) {
                Logger::info('MemoryManager::extractMemoryFromMessage skipped', ['user_id' => $userId]);
                return;
            }

            $memories = $json['memories'] ?? [];
            if (empty($memories)) return;

            foreach ($memories as $mem) {
                $text = trim($mem['text'] ?? '');
                $importance = (int)($mem['importance'] ?? 5);
                if (!empty($text) && mb_strlen($text) > 3) {
                    $this->addMemory($userId, $text, 'extracted', $userMessage, $importance);
                }
            }

            Logger::info('MemoryManager::extractMemoryFromMessage saved', ['user_id' => $userId, 'count' => count($memories)]);

        } catch (\Throwable $e) {
            Logger::error('MemoryManager::extractMemoryFromMessage error', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function extractJsonFromResponse(string $text): ?array
    {
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m)) {
            $decoded = json_decode($m[1], true);
            if ($decoded !== null) return $decoded;
        }
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if ($decoded !== null) return $decoded;
        }
        return null;
    }

    private function getModelData(string $modelName): array
    {
        try {
            $row = $this->db->query(
                "SELECT * FROM ai_text_models WHERE name = ? AND is_active = 1",
                [$modelName]
            )->fetch();
            if ($row) {
                return [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'provider' => $row['provider'] ?? 'openrouter',
                    'cost_per_input_char' => (float)($row['cost_per_input_char'] ?? 0.000001),
                    'cost_per_output_char' => (float)($row['cost_per_output_char'] ?? 0.000002),
                    'free_model' => (int)($row['free_model'] ?? 0),
                ];
            }
        } catch (\Throwable $e) {}
        return [
            'name' => $modelName,
            'provider' => 'openrouter',
            'cost_per_input_char' => 0.000001,
            'cost_per_output_char' => 0.000002,
            'free_model' => 0,
        ];
    }

    // ———— Explicit memory save ————

    public function saveExplicitMemory(int $userId, string $userMessage): void
    {
        if (!$this->isEnabled()) return;

        $patterns = [
            '/یادت باشه\s*(.*)/u',
            '/به خاطر بسپار\s*(.*)/u',
            '/ذخیره کن\s*(.*)/u',
            '/فراموش نکن\s*(.*)/u',
            '/یادت باشه:\s*(.*)/u',
            '/به خاطر بسپار:\s*(.*)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $userMessage, $matches)) {
                $memoryText = trim($matches[1]);
                if (!empty($memoryText) && mb_strlen($memoryText) > 3) {
                    $this->addMemory($userId, $memoryText, 'explicit', $userMessage, 7);
                }
                return;
            }
        }

        if (preg_match('/(یادت باشه|به خاطر بسپار|ذخیره کن|فراموش نکن)/u', $userMessage)) {
            $cleaned = preg_replace('/^\s*(یادت باشه|به خاطر بسپار|ذخیره کن|فراموش نکن)[:\s]*/u', '', $userMessage);
            $cleaned = trim($cleaned);
            if (!empty($cleaned) && mb_strlen($cleaned) > 3) {
                $this->addMemory($userId, $cleaned, 'explicit', $userMessage, 7);
            }
        }
    }

    // ———— Delete ————

    public function deleteMemory(int $memoryId, int $userId): bool
    {
        if (!$this->isEnabled()) return false;
        try {
            $this->db->query("UPDATE user_memories SET is_active = 0 WHERE id = ? AND user_id = ?", [$memoryId, $userId]);
            return true;
        } catch (\Throwable $e) {
            Logger::error('MemoryManager::deleteMemory error', ['memory_id' => $memoryId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function clearAllMemories(int $userId): bool
    {
        if (!$this->isEnabled()) return false;
        try {
            $this->db->query("UPDATE user_memories SET is_active = 0 WHERE user_id = ?", [$userId]);
            return true;
        } catch (\Throwable $e) {
            Logger::error('MemoryManager::clearAllMemories error', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function getMemoryCount(int $userId): int
    {
        if (!$this->isEnabled()) return 0;
        try {
            $row = $this->db->query("SELECT COUNT(*) as c FROM user_memories WHERE user_id = ? AND is_active = 1", [$userId])->fetch();
            return (int)($row['c'] ?? 0);
        } catch (\Throwable $e) { return 0; }
    }

    /**
     * Get a single memory by ID (scoped to user).
     */
    public function getMemoryById(int $memoryId, int $userId): ?array
    {
        try {
            $row = $this->db->query(
                "SELECT id, memory_text, memory_type, importance, created_at FROM user_memories WHERE id = ? AND user_id = ? AND is_active = 1",
                [$memoryId, $userId]
            )->fetch();
            return $row ?: null;
        } catch (\Throwable $e) { return null; }
    }

    // ———— User-level toggle ————

    /**
     * Toggle memory module on/off for a specific user.
     * Uses user_memory_settings table.
     */
    public function toggleMemoryForUser(int $userId, bool $enabled): void
    {
        try {
            $existing = $this->db->query(
                "SELECT id FROM user_memory_settings WHERE user_id = ?",
                [$userId]
            )->fetch();
            if ($existing) {
                $this->db->query(
                    "UPDATE user_memory_settings SET memory_disabled = ?, updated_at = NOW() WHERE user_id = ?",
                    [$enabled ? 0 : 1, $userId]
                );
            } else {
                $this->db->query(
                    "INSERT INTO user_memory_settings (user_id, memory_disabled) VALUES (?, ?)",
                    [$userId, $enabled ? 0 : 1]
                );
            }
        } catch (\Throwable $e) {
            Logger::error('MemoryManager::toggleMemoryForUser error', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Check if memory is disabled for a specific user.
     * Returns true if user has explicitly disabled it.
     */
    public function isDisabledForUser(int $userId): bool
    {
        try {
            $row = $this->db->query(
                "SELECT memory_disabled FROM user_memory_settings WHERE user_id = ?",
                [$userId]
            )->fetch();
            return $row && (int)$row['memory_disabled'] === 1;
        } catch (\Throwable $e) { return false; }
    }
}
