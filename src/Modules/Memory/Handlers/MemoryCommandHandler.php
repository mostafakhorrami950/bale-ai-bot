<?php

namespace Modules\Memory\Handlers;

use Modules\Bot\BaleClient;
use Modules\Memory\MemoryManager;
use Database\Logger;
use Database\Database;

class MemoryCommandHandler
{
    private BaleClient $baleClient;
    private MemoryManager $memoryManager;

    public function __construct(BaleClient $baleClient, MemoryManager $memoryManager)
    {
        $this->baleClient = $baleClient;
        $this->memoryManager = $memoryManager;
    }

    /**
     * Handle a memory-related request (called by Dispatcher with $update).
     */
    public function handle($update): void
    {
        $chatId = $update->getChatId();
        $userId = $update->getUserId();
        $text = $update->getText() ?? '';
        $callbackData = $update->getCallbackData() ?? '';

        if (!$chatId || !$userId) return;

        if (!$this->memoryManager->isEnabled()) {
            $this->baleClient->sendMessage($chatId, "🧠 ماژول حافظه در حال حاضر غیرفعال است.");
            return;
        }

        // Resolve internal user ID
        $internalId = $this->resolveInternalId($userId);
        if (!$internalId) {
            $this->baleClient->sendMessage($chatId, "⚠️ کاربر یافت نشد.");
            return;
        }

        // Handle callback data
        if ($callbackData === 'show_memory') {
            $this->showMemories($chatId, $internalId);
            return;
        }
        if ($callbackData === 'clear_memory') {
            $this->deleteAllMemories($chatId, $internalId);
            return;
        }

        // Handle text commands
        $text = trim($text);
        if ($text === '🧠 حافظه من') {
            $this->showMemories($chatId, $internalId);
            return;
        }
        if ($text === '🗑 پاک کردن حافظه') {
            $this->deleteAllMemories($chatId, $internalId);
            return;
        }

        // Unknown
        $this->showHelp($chatId);
    }

    /**
     * Show user's saved memories.
     */
    private function showMemories(int $chatId, int $userId): void
    {
        $memories = $this->memoryManager->getUserMemories($userId, 20);

        if (empty($memories)) {
            $this->baleClient->sendMessage(
                $chatId,
                "🧠 **حافظه شما خالی است**\n\n"
                . "شما می‌توانید با گفتن «یادت باشه [متن]» اطلاعاتی را به خاطر بسپارید.\n"
                . "مثال: «یادت باشه اسم من علی است»\n\n"
                . "💡 همچنین ربات به طور خودکار اطلاعات مهم مانند:\n"
                . "• نام، سن، شغل\n"
                . "• علایق و سرگرمی‌ها\n"
                . "• شهر و آدرس\n"
                . "را از گفتگوهای شما استخراج و ذخیره می‌کند."
            );
            return;
        }

        $msg = "🧠 **حافظه شما** ({$this->memoryManager->getMemoryCount($userId)} مورد)\n\n";
        $msg .= "برای حذف همه، دکمه «🗑️ پاک کردن حافظه» را بزنید.\n\n";

        foreach ($memories as $i => $mem) {
            $icon = $mem['memory_type'] === 'explicit' ? '📝' : '🔍';
            $importance = str_repeat('⭐', min(3, max(1, (int)ceil($mem['importance'] / 3))));
            $date = substr($mem['created_at'], 0, 10);
            $num = $i + 1;
            $msg .= "{$num}. {$icon} {$mem['memory_text']}\n";
            $msg .= "   {$importance} | {$date}\n\n";
        }

        $this->baleClient->sendMessage($chatId, $msg);
    }

    /**
     * Delete all memories for the user.
     */
    private function deleteAllMemories(int $chatId, int $userId): void
    {
        $success = $this->memoryManager->clearAllMemories($userId);

        if ($success) {
            $this->baleClient->sendMessage(
                $chatId,
                "🗑 **همه خاطرات شما پاک شد.**\n\n"
                . "برای ذخیره اطلاعات جدید، از دستور «یادت باشه [متن]» استفاده کنید."
            );
            Logger::info('Memory::clearAllMemories', ['user_id' => $userId]);
        } else {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در پاک کردن حافظه. لطفاً دوباره تلاش کنید.");
        }
    }

    /**
     * Show help for memory commands.
     */
    private function showHelp(int $chatId): void
    {
        $this->baleClient->sendMessage(
            $chatId,
            "🧠 **راهنمای حافظه**\n\n"
            . "• «یادت باشه [متن]» → ذخیره اطلاعات\n"
            . "• «🧠 حافظه من» → مشاهده حافظه\n"
            . "• «🗑️ پاک کردن حافظه» → حذف همه\n\n"
            . "💡 ربات به طور خودکار اطلاعات مهم را ذخیره می‌کند."
        );
    }

    /**
     * Resolve internal user ID from Bale user ID.
     */
    private function resolveInternalId(int $baleUserId): ?int
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT id FROM users WHERE bale_user_id = ?", [$baleUserId]);
            $row = $stmt->fetch();
            return $row ? (int) $row['id'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}