<?php

namespace Modules\Memory\Handlers;

use Modules\Bot\BaleClient;
use Modules\Memory\MemoryManager;
use Database\Logger;

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
     * Handle a memory-related request.
     */
    public function handle(int $chatId, int $userId, string $text): void
    {
        if (!$this->memoryManager->isEnabled()) {
            $this->baleClient->sendMessage($chatId, "🧠 ماژول حافظه در حال حاضر غیرفعال است.");
            return;
        }

        $text = trim($text);

        // Show memories: /حافظه or "🧠 حافظه من"
        if ($text === '/حافظه' || $text === '🧠 حافظه من') {
            $this->showMemories($chatId, $userId);
            return;
        }

        // Delete all memories: /حذف_حافظه or "🗑 پاک کردن حافظه"
        if ($text === '/حذف_حافظه' || $text === '🗑 پاک کردن حافظه') {
            $this->deleteAllMemories($chatId, $userId);
            return;
        }

        // Unknown command
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
        $msg .= "برای حذف همه، /حذف_حافظه را بزنید.\n\n";

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
            . "دستورات موجود:\n"
            . "• `🧠 حافظه من` - مشاهده اطلاعات ذخیره شده\n"
            . "• `🗑 پاک کردن حافظه` - حذف تمام اطلاعات ذخیره شده\n\n"
            . "**نحوه ذخیره اطلاعات:**\n"
            . "به ربات بگویید:\n"
            . "• «یادت باشه [متن]»\n"
            . "• «به خاطر بسپار [متن]»\n"
            . "• «ذخیره کن [متن]»\n\n"
            . "**مثال‌ها:**\n"
            . "`یادت باشه اسم من سارا است`\n"
            . "`به خاطر بسپار من برنامه‌نویس هستم`\n"
            . "`ذخیره کن رنگ مورد علاقه‌ام آبی است`\n\n"
            . "💡 ربات همچنین به طور خودکار اطلاعات مهم را از گفتگوها استخراج می‌کند."
        );
    }
}