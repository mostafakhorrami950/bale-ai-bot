<?php

namespace Modules\Memory;

use Database\Logger;

class Hooks
{
    private MemoryManager $memoryManager;

    public function __construct(MemoryManager $memoryManager)
    {
        $this->memoryManager = $memoryManager;
    }

    /**
     * Called before sending a request to AI.
     * Injects memory context into the system prompt if module is enabled.
     */
    public function onBeforeChatRequest(int $userId, string &$systemPrompt): void
    {
        if (!$this->memoryManager->isEnabled()) return;

        try {
            $memoryContext = $this->memoryManager->buildMemoryContext($userId);

            if (!empty($memoryContext)) {
                $systemPrompt .= "\n\n=== اطلاعات حافظه کاربر ===\n{$memoryContext}\n===========================";
                Logger::info('Memory::onBeforeChatRequest', [
                    'user_id' => $userId,
                    'context_length' => mb_strlen($memoryContext),
                ]);
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
                // Check if we already have a recent summary
                $summaries = $this->memoryManager->getRecentSummaries($userId, 1);
                $lastSummaryTime = !empty($summaries) ? strtotime($summaries[0]['created_at']) : 0;
                $hoursSinceLastSummary = (time() - $lastSummaryTime) / 3600;

                // Summarize only if last summary was more than 1 hour ago
                if ($hoursSinceLastSummary > 1 || empty($summaries)) {
                    $this->memoryManager->summarizeConversation($userId, $conversationText);
                }
            }
        } catch (\Throwable $e) {
            Logger::error('Memory::checkSummarization error', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}