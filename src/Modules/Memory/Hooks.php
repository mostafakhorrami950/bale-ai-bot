<?php

namespace Modules\Memory;

use Database\Logger;

class Hooks
{
    private MemoryManager $memoryManager;
    private array $injectedConversations = [];

    public function __construct(MemoryManager $memoryManager)
    {
        $this->memoryManager = $memoryManager;
    }

    /**
     * Called before sending a request to AI.
     * Injects memory context into the system prompt ONLY ONCE per conversation.
     *
     * @param int    $userId      Internal user ID
     * @param string &$systemPrompt  System prompt text (modified by reference)
     * @param string $convKey     Unique conversation key (e.g. "conv_{id}")
     */
    public function onBeforeChatRequest(int $userId, string &$systemPrompt, string $convKey = ''): void
    {
        if (!$this->memoryManager->isEnabled()) return;

        // Only inject once per conversation
        if (!empty($convKey) && in_array($convKey, $this->injectedConversations, true)) {
            Logger::info('Memory::onBeforeChatRequest skipped (already injected)', [
                'user_id' => $userId,
                'conv_key' => $convKey,
            ]);
            return;
        }

        try {
            $memoryContext = $this->memoryManager->buildMemoryContext($userId);

            if (!empty($memoryContext)) {
                // Privacy-aware system prompt injection
                $systemPrompt .= "\n\n=== اطلاعات حافظه کاربر ===\n{$memoryContext}\n===========================\n\n"
                    . "⚠️ **دستورالعمل حریم خصوصی:**\n"
                    . "1. اطلاعات حافظه بالا فقط برای پاسخگویی بهتر در اختیار تو قرار گرفته است.\n"
                    . "2. هرگز اطلاعات شخصی کاربر را فاش نکن، مگر اینکه خود کاربر از تو درباره آن سوال کند.\n"
                    . "3. اگر کاربر درباره چیزی سوال نکرده، اطلاعات حافظه را در پاسخ خود ذکر نکن.\n"
                    . "4. از اطلاعات حافظه برای شخصی‌سازی پاسخ‌ها استفاده کن، بدون اینکه اشاره‌ای به آن‌ها داشته باشی.\n"
                    . "5. اگر کاربر چیزی جدید درباره خودش گفت که با حافظه مغایرت دارد، حافظه جدید را ملاک قرار بده.\n"
                    . "6. هدف: کاربر احساس راحتی کند و نفهمد که اطلاعاتش ذخیره شده، مگر اینکه خودش بپرسد.\n"
                    . "===========================";

                Logger::info('Memory::onBeforeChatRequest injected', [
                    'user_id' => $userId,
                    'conv_key' => $convKey ?: 'unknown',
                    'context_length' => mb_strlen($memoryContext),
                ]);

                // Mark as injected for this conversation
                if (!empty($convKey)) {
                    $this->injectedConversations[] = $convKey;
                }
            }
        } catch (\Throwable $e) {
            Logger::error('Memory::onBeforeChatRequest error', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Called after receiving AI response.
     * Checks if user asked to save something, and auto-extracts important info.
     */
    public function onAfterChatResponse(int $userId, string $userMessage): void
    {
        if (!$this->memoryManager->isEnabled()) return;

        try {
            // Check for explicit memory save commands
            if (preg_match('/(یادت باشه|به خاطر بسپار|ذخیره کن|فراموش نکن)/u', $userMessage)) {
                $this->memoryManager->saveExplicitMemory($userId, $userMessage);
            }

            // Auto-extract important personal information
            $this->memoryManager->extractMemoryFromMessage($userId, $userMessage);
        } catch (\Throwable $e) {
            Logger::error('Memory::onAfterChatResponse error', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Check if conversation summarization is needed based on message count.
     */
    public function checkSummarization(int $userId, int $currentMessageCount, string $conversationText): void
    {
        if (!$this->memoryManager->isEnabled()) return;

        try {
            $threshold = (int)$this->memoryManager->getConfig('summarization_threshold', 20);
            if ($currentMessageCount >= $threshold) {
                $summaries = $this->memoryManager->getRecentSummaries($userId, 1);
                $lastSummaryTime = !empty($summaries) ? strtotime($summaries[0]['created_at']) : 0;
                $hoursSinceLastSummary = (time() - $lastSummaryTime) / 3600;

                if ($hoursSinceLastSummary > 1 || empty($summaries)) {
                    $this->memoryManager->summarizeConversation($userId, $conversationText);
                }
            }
        } catch (\Throwable $e) {
            Logger::error('Memory::checkSummarization error', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}