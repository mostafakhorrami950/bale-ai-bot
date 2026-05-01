<?php

namespace Modules\Memory;

use Database\Database;
use Database\Logger;

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
     * Auto-extract important information from a user message.
     * Uses keyword detection (no AI call needed — pure PHP).
     */
    public function extractMemoryFromMessage(int $userId, string $userMessage): void
    {
        if (!$this->isEnabled()) return;

        $keywords = [
            'اسم من' => 'name',
            'نام من' => 'name',
            'من را' => 'personal',
            'دوست دارم' => 'preference',
            'علاقه' => 'preference',
            'شغل من' => 'job',
            'کار من' => 'job',
            'سن من' => 'age',
            'زادروز' => 'birthday',
            'تولد' => 'birthday',
            'آدرس' => 'address',
            'شهر من' => 'city',
            'کشور' => 'country',
            'شماره تماس' => 'phone',
            'ایمیل' => 'email',
            'حرفه' => 'profession',
            'تحصیلات' => 'education',
            'رشته' => 'field_of_study',
            'زبان' => 'language',
            'ورزش' => 'sport',
            'سرگرمی' => 'hobby',
            'غذای مورد علاقه' => 'food',
            'رنگ مورد علاقه' => 'color',
            'فیلم مورد علاقه' => 'movie',
            'کتاب مورد علاقه' => 'book',
            'موسیقی' => 'music',
        ];

        foreach ($keywords as $keyword => $type) {
            if (mb_strpos($userMessage, $keyword) !== false) {
                // Extract the sentence containing the keyword
                $sentences = preg_split('/([.?!\n])+/u', $userMessage, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($sentences as $sentence) {
                    $sentence = trim($sentence);
                    if (mb_strpos($sentence, $keyword) !== false && mb_strlen($sentence) > 10) {
                        // Importance based on type
                        $importanceMap = [
                            'name' => 10, 'age' => 8, 'birthday' => 8, 'phone' => 9, 'email' => 9,
                            'preference' => 5, 'hobby' => 4, 'sport' => 4, 'food' => 3, 'color' => 2,
                        ];
                        $importance = $importanceMap[$type] ?? 3;

                        $this->addMemory($userId, $sentence, 'extracted', $userMessage, $importance);
                        break;
                    }
                }
            }
        }
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