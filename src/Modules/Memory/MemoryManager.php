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
     * Auto-extract important personal information using AI.
     * Sends the user message to an AI model with a system prompt asking it
     * to extract important personal info. Only saves if AI determines it's important.
     */
    public function extractMemoryFromMessage(int $userId, string $userMessage): void
    {
        if (!$this->isEnabled()) return;

        // Skip very short messages
        if (mb_strlen(trim($userMessage)) < 10) return;

        try {
            // Get the extraction model from config (default to first active text model)
            $modelName = $this->getConfig('extraction_model', '');
            if (empty($modelName)) {
                // Try to get default text model from settings
                $row = $this->db->query(
                    "SELECT value FROM settings WHERE key_name = 'default_text_model'"
                )->fetch();
                if ($row && !empty($row['value'])) {
                    $modelRow = $this->db->query(
                        "SELECT name FROM ai_text_models WHERE id = ? AND is_active = 1",
                        [(int)$row['value']]
                    )->fetch();
                    if ($modelRow) {
                        $modelName = $modelRow['name'];
                    }
                }
            }
            if (empty($modelName)) {
                // Fallback: get first active text model
                $modelRow = $this->db->query(
                    "SELECT name FROM ai_text_models WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
                )->fetch();
                if ($modelRow) {
                    $modelName = $modelRow['name'];
                }
            }
            if (empty($modelName)) return; // No model available

            // Build the AI extraction prompt
            $systemPrompt = "تو یک دستیار هوشمند هستی که وظیفه‌ات استخراج اطلاعات شخصی مهم از پیام‌های کاربران است.\n\n"
                . "قوانین:\n"
                . "1. فقط اطلاعات شخصی مهم را استخراج کن (نام، سن، شغل، علایق، آدرس، شماره تماس، وضعیت سلامتی، اهداف، تاریخ‌های مهم و ...)\n"
                . "2. اگر اطلاعات مهمی در پیام وجود ندارد، خالی برگردان\n"
                . "3. خروجی را به صورت JSON برگردان با این ساختار:\n"
                . "{\n"
                . "  \"has_important_info\": true/false,\n"
                . "  \"memories\": [\n"
                . "    {\"text\": \"متن حافظه به فارسی\", \"importance\": 1-10}\n"
                . "  ]\n"
                . "}\n"
                . "4. importance از 1 (کم اهمیت) تا 10 (بسیار مهم) باشد\n"
                . "5. نام = 10, سن = 8, شغل = 8, شماره تماس = 9, آدرس = 8, وضعیت سلامتی = 7, اهداف = 6, علایق = 5\n"
                . "6. حتماً خروجی JSON معتبر برگردان";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "پیام کاربر:\n" . $userMessage],
            ];

            // Use ChatService to call AI
            $chatService = new ChatService();
            $modelData = $this->getModelData($modelName);
            $result = $chatService->chat($messages, $modelName, $modelData);

            if (isset($result['error'])) {
                Logger::error('MemoryManager::extractMemoryFromMessage AI error', [
                    'user_id' => $userId,
                    'error' => $result['error'],
                ]);
                return;
            }

            $responseText = $result['response'] ?? '';
            if (empty($responseText)) return;

            // Parse JSON from response
            $json = $this->extractJsonFromResponse($responseText);
            if ($json === null) {
                Logger::error('MemoryManager::extractMemoryFromMessage parse error', [
                    'user_id' => $userId,
                    'response' => mb_substr($responseText, 0, 500),
                ]);
                return;
            }

            if (!isset($json['has_important_info']) || $json['has_important_info'] !== true) {
                // AI determined no important info — skip
                Logger::info('MemoryManager::extractMemoryFromMessage skipped', [
                    'user_id' => $userId,
                    'reason' => 'AI determined no important info',
                ]);
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

            Logger::info('MemoryManager::extractMemoryFromMessage saved', [
                'user_id' => $userId,
                'count' => count($memories),
            ]);

        } catch (\Throwable $e) {
            Logger::error('MemoryManager::extractMemoryFromMessage error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract JSON from AI response text (handles markdown code blocks).
     */
    private function extractJsonFromResponse(string $text): ?array
    {
        // Try to find JSON in code blocks first
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m)) {
            $decoded = json_decode($m[1], true);
            if ($decoded !== null) return $decoded;
        }

        // Try to find JSON directly
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if ($decoded !== null) return $decoded;
        }

        return null;
    }

    /**
     * Get model data array for ChatService.
     */
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
        } catch (\Throwable $e) {
            // ignore
        }
        return [
            'name' => $modelName,
            'provider' => 'openrouter',
            'cost_per_input_char' => 0.000001,
            'cost_per_output_char' => 0.000002,
            'free_model' => 0,
        ];
    }

    /**
     * Save explicit memory when user says "یادت باشه" type commands.
     */
    public function saveExplicitMemory(int $userId, string $userMessage): void
    {
        if (!$this->isEnabled()) return;

        // Extract the actual memory text after commands like "یادت باشه"
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

        // If no pattern matched but the message contains memory keywords, extract it
        if (preg_match('/(یادت باشه|به خاطر بسپار|ذخیره کن|فراموش نکن)/u', $userMessage)) {
            $cleaned = preg_replace('/^\s*(یادت باشه|به خاطر بسپار|ذخیره کن|فراموش نکن)[:\s]*/u', '', $userMessage);
            $cleaned = trim($cleaned);
            if (!empty($cleaned) && mb_strlen($cleaned) > 3) {
                $this->addMemory($userId, $cleaned, 'explicit', $userMessage, 7);
            }
        }
    }

    /**
     * Build memory context string to inject into system prompt.
     */
    public function buildMemoryContext(int $userId): string
    {
        if (!$this->isEnabled()) return '';

        $parts = [];

        // Add active memories
        $memories = $this->getUserMemories($userId);
        if (!empty($memories)) {
            $memoryLines = [];
            foreach ($memories as $mem) {
                $memoryLines[] = '• ' . $mem['memory_text'];
            }
            $parts[] = "📝 اطلاعات ذخیره شده درباره کاربر:\n" . implode("\n", $memoryLines);
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
     * Delete a specific memory by ID.
     */
    public function deleteMemory(int $memoryId, int $userId): bool
    {
        if (!$this->isEnabled()) return false;

        try {
            $this->db->query(
                "UPDATE user_memories SET is_active = 0 WHERE id = ? AND user_id = ?",
                [$memoryId, $userId]
            );
            return true;
        } catch (\Throwable $e) {
            Logger::error('MemoryManager::deleteMemory error', ['memory_id' => $memoryId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Delete all memories for a user.
     */
    public function clearAllMemories(int $userId): bool
    {
        if (!$this->isEnabled()) return false;

        try {
            $this->db->query(
                "UPDATE user_memories SET is_active = 0 WHERE user_id = ?",
                [$userId]
            );
            return true;
        } catch (\Throwable $e) {
            Logger::error('MemoryManager::clearAllMemories error', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Create a summary of recent conversation history.
     * Uses the AI service if available, otherwise uses simple truncation.
     */
    public function summarizeConversation(int $userId, string $conversationText): ?string
    {
        if (!$this->isEnabled()) return null;

        try {
            $summary = null;

            // Try AI summarization if AI service is available
            if ($this->aiService !== null && method_exists($this->aiService, 'chat')) {
                $systemPrompt = "خلاصه‌ای مختصر و مفید از گفتگوی زیر به فارسی بنویس. فقط نکات مهم را بنویس.";
                $messages = [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $conversationText],
                ];
                $result = $this->aiService->chat($messages, $this->getConfig('summarization_model', 'gpt-4o-mini'));
                if (!isset($result['error']) && !empty($result['response'])) {
                    $summary = $result['response'];
                }
            }

            // Fallback: simple truncation-based summary
            if (empty($summary)) {
                $lines = explode("\n", $conversationText);
                $summary = implode("\n", array_slice($lines, 0, 20));
                if (mb_strlen($summary) > 500) {
                    $summary = mb_substr($summary, 0, 500) . '...';
                }
            }

            if (!empty($summary)) {
                $messageCount = mb_substr_count($conversationText, "\n") + 1;
                $this->db->query(
                    "INSERT INTO conversation_summaries (user_id, summary_text, message_count) VALUES (?, ?, ?)",
                    [$userId, $summary, $messageCount]
                );
            }

            return $summary;
        } catch (\Throwable $e) {
            Logger::error('MemoryManager::summarizeConversation error', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get the count of active memories for a user.
     */
    public function getMemoryCount(int $userId): int
    {
        if (!$this->isEnabled()) return 0;

        try {
            $row = $this->db->query(
                "SELECT COUNT(*) as c FROM user_memories WHERE user_id = ? AND is_active = 1",
                [$userId]
            )->fetch();
            return (int)($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}