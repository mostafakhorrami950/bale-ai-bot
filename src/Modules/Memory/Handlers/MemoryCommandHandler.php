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

    // Track users who are adding memory (state: awaiting text)
    private static array $addingMemory = [];

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

        // ─── Handle callback data ───
        if ($callbackData === 'show_memory') {
            $this->showMemories($chatId, $internalId);
            return;
        }
        if ($callbackData === 'clear_memory') {
            $this->confirmClearMemory($chatId, $internalId);
            return;
        }
        if ($callbackData === 'confirm_clear_memory') {
            $this->deleteAllMemories($chatId, $internalId);
            return;
        }
        if ($callbackData === 'cancel_clear_memory') {
            $this->baleClient->sendMessage($chatId, "✅ عملیات پاک کردن حافظه لغو شد.");
            return;
        }
        if ($callbackData === 'toggle_memory') {
            $this->toggleMemory($chatId, $internalId, $userId);
            return;
        }
        if ($callbackData === 'add_memory') {
            $this->askMemoryText($chatId, $userId, $internalId);
            return;
        }

        // Delete specific memory: delete_mem_{id}
        if (str_starts_with($callbackData, 'delete_mem_')) {
            $memId = (int) str_replace('delete_mem_', '', $callbackData);
            $this->deleteSingleMemory($chatId, $internalId, $memId, $userId);
            return;
        }

        // Save memory with importance: mem_imp_{encoded_text}_{stars}
        if (str_starts_with($callbackData, 'mem_imp_')) {
            // Format: mem_imp_{urlencoded_text}_{stars}
            $rest = substr($callbackData, 8); // remove "mem_imp_"
            $lastUnderscore = strrpos($rest, '_');
            if ($lastUnderscore !== false) {
                $stars = (int) substr($rest, $lastUnderscore + 1);
                $encodedText = substr($rest, 0, $lastUnderscore);
                $memoryText = urldecode($encodedText);
                if (!empty($memoryText) && $stars >= 1 && $stars <= 5) {
                    $this->memoryManager->addMemory($internalId, $memoryText, 'explicit', null, $stars * 2);
                    $this->baleClient->sendMessage(
                        $chatId,
                        "✅ **اطلاعات ذخیره شد!**\n\n"
                      . "📝 " . $memoryText . "\n"
                      . "⭐ اهمیت: " . str_repeat('⭐', $stars) . " (" . ($stars * 2) . "/10)\n\n"
                      . "برای مشاهده، از دکمه «🧠 حافظه من» استفاده کنید."
                    );
                    Logger::info('Memory::addWithImportance', [
                        'user_id' => $internalId,
                        'importance' => $stars * 2,
                        'text' => mb_substr($memoryText, 0, 100),
                    ]);
                }
            }
            return;
        }

        // ─── Handle text (from add_memory flow) ───
        $text = trim($text);
        if (!empty($text) && isset(self::$addingMemory[$userId]) && self::$addingMemory[$userId] === true) {
            unset(self::$addingMemory[$userId]);
            $this->showImportanceButtons($chatId, $userId, $text);
            return;
        }

        // Handle text commands
        if ($text === '🧠 حافظه من') {
            $this->showMemories($chatId, $internalId);
            return;
        }
        if ($text === '🗑 پاک کردن حافظه') {
            $this->confirmClearMemory($chatId, $internalId);
            return;
        }

        // Unknown
        $this->showHelp($chatId);
    }

    /**
     * Show each memory as a separate message with delete button.
     */
    private function showMemories(int $chatId, int $userId): void
    {
        $memories = $this->memoryManager->getUserMemories($userId, 50);

        if (empty($memories)) {
            $this->baleClient->sendMessage(
                $chatId,
                "🧠 **حافظه شما خالی است**\n\n"
                . "شما می‌توانید با زدن دکمه «➕ افزودن به حافظه» اطلاعات جدید ذخیره کنید.\n"
                . "همچنین ربات به طور خودکار اطلاعات مهم را از گفتگوهای شما استخراج می‌کند."
            );
            return;
        }

        // Send count first
        $this->baleClient->sendMessage(
            $chatId,
            "🧠 **حافظه شما** — {$this->memoryManager->getMemoryCount($userId)} مورد"
        );

        // Send each memory individually with delete button
        foreach ($memories as $mem) {
            $icon = $mem['memory_type'] === 'explicit' ? '📝' : '🔍';
            $importance = str_repeat('⭐', min(5, max(1, (int)ceil($mem['importance'] / 2))));
            $date = substr($mem['created_at'], 0, 10);

            $msg = "{$icon} {$mem['memory_text']}\n"
                 . "{$importance} | {$date}";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🗑️ حذف', 'callback_data' => 'delete_mem_' . $mem['id']]],
                ]
            ];

            $this->baleClient->sendMessage($chatId, $msg, $keyboard);
        }
    }

    /**
     * Ask for confirmation before clearing all memories.
     */
    private function confirmClearMemory(int $chatId, int $userId): void
    {
        $count = $this->memoryManager->getMemoryCount($userId);
        if ($count === 0) {
            $this->baleClient->sendMessage($chatId, "🧠 حافظه شما در حال حاضر خالی است.");
            return;
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✅ تایید میکنم، همه پاک شود', 'callback_data' => 'confirm_clear_memory']],
                [['text' => '❌ تایید نمیکنم', 'callback_data' => 'cancel_clear_memory']],
            ]
        ];

        $this->baleClient->sendMessage(
            $chatId,
            "⚠️ **آیا مطمئن هستید؟**\n\n"
          . "شما {$count} مورد اطلاعات حافظه دارید.\n"
          . "پس از حذف، امکان بازگشت وجود ندارد.\n\n"
          . "💡 اگر می‌خواهید فقط برخی موارد حذف شوند،\n"
          . "روی دکمه «🧠 حافظه من» کلیک کنید\n"
          . "و هر مورد را جداگانه حذف نمایید.",
            $keyboard
        );
    }

    /**
     * Delete all memories.
     */
    private function deleteAllMemories(int $chatId, int $userId): void
    {
        $success = $this->memoryManager->clearAllMemories($userId);

        if ($success) {
            $this->baleClient->sendMessage(
                $chatId,
                "🗑 **همه خاطرات شما پاک شد.**\n\n"
              . "برای ذخیره اطلاعات جدید، از دکمه «➕ افزودن به حافظه» استفاده کنید."
            );
            Logger::info('Memory::clearAllMemories', ['user_id' => $userId]);
        } else {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در پاک کردن حافظه. لطفاً دوباره تلاش کنید.");
        }
    }

    /**
     * Delete a single memory by ID.
     */
    private function deleteSingleMemory(int $chatId, int $userId, int $memId, int $baleUserId): void
    {
        $success = $this->memoryManager->deleteMemory($memId, $userId);

        if ($success) {
            $this->baleClient->sendMessage($chatId, "🗑️ آن مورد از حافظه حذف شد.");
            Logger::info('Memory::deleteSingleMemory', ['user_id' => $userId, 'memory_id' => $memId]);

            // Show remaining memories
            $remaining = $this->memoryManager->getMemoryCount($userId);
            if ($remaining > 0) {
                $this->showMemories($chatId, $userId);
            } else {
                $this->baleClient->sendMessage($chatId, "🧠 حافظه شما خالی است.");
            }
        } else {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در حذف این مورد. لطفاً دوباره تلاش کنید.");
        }
    }

    /**
     * Toggle memory on/off for the user.
     */
    private function toggleMemory(int $chatId, int $internalId, int $baleUserId): void
    {
        $isDisabled = $this->memoryManager->isDisabledForUser($internalId);
        $this->memoryManager->toggleMemoryForUser($internalId, $isDisabled); // if disabled, enable; if enabled, disable

        if ($isDisabled) {
            $this->baleClient->sendMessage(
                $chatId,
                "✅ **حافظه فعال شد**\n\n"
              . "از این به بعد، ربات اطلاعات مهم را ذخیره و در پاسخ‌ها استفاده می‌کند.\n"
              . "می‌توانید با دکمه «🚫 غیرفعال کردن حافظه» دوباره آن را خاموش کنید."
            );
        } else {
            $this->baleClient->sendMessage(
                $chatId,
                "🚫 **حافظه غیرفعال شد**\n\n"
              . "ربات دیگر اطلاعات جدیدی ذخیره نمی‌کند و از اطلاعات قبلی استفاده نمی‌کند.\n"
              . "می‌توانید با دکمه «✅ فعال کردن حافظه» دوباره آن را روشن کنید."
            );
        }
        Logger::info('Memory::toggleMemory', ['user_id' => $internalId, 'disabled' => !$isDisabled]);
    }

    /**
     * Ask user to type the memory text.
     */
    private function askMemoryText(int $chatId, int $baleUserId, int $internalId): void
    {
        self::$addingMemory[$baleUserId] = true;
        $this->baleClient->sendMessage(
            $chatId,
            "✏️ **متن مورد نظر خود را بنویسید:**\n\n"
          . "مثال:\n"
          . "• اسم من علی است\n"
          . "• کد پستی من ۱۲۳۴۵۶۷۸۹۰ است\n"
          . "• رنگ مورد علاقه‌ام آبی است\n\n"
          . "نکته: تایپ /cancel برای لغو"
        );
    }

    /**
     * Show importance selection buttons (1-5 stars).
     */
    private function showImportanceButtons(int $chatId, int $baleUserId, string $memoryText): void
    {
        if (mb_strlen($memoryText) < 2 || mb_strlen($memoryText) > 1000) {
            $this->baleClient->sendMessage($chatId, "⚠️ متن باید بین 2 تا 1000 کاراکتر باشد.");
            return;
        }

        $encoded = urlencode($memoryText);
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⭐', 'callback_data' => 'mem_imp_' . $encoded . '_1'],
                    ['text' => '⭐⭐', 'callback_data' => 'mem_imp_' . $encoded . '_2'],
                    ['text' => '⭐⭐⭐', 'callback_data' => 'mem_imp_' . $encoded . '_3'],
                    ['text' => '⭐⭐⭐⭐', 'callback_data' => 'mem_imp_' . $encoded . '_4'],
                    ['text' => '⭐⭐⭐⭐⭐', 'callback_data' => 'mem_imp_' . $encoded . '_5'],
                ]
            ]
        ];

        $this->baleClient->sendMessage(
            $chatId,
            "📝 متن شما:\n" . $memoryText . "\n\n"
          . "⭐ **اهمیت این اطلاعات چقدر است؟**\n"
          . "1 = کمترین اهمیت، 5 = بیشترین اهمیت",
            $keyboard
        );
    }

    /**
     * Show help for memory commands.
     */
    private function showHelp(int $chatId): void
    {
        $this->baleClient->sendMessage(
            $chatId,
            "🧠 **راهنمای حافظه**\n\n"
          . "• «🧠 حافظه من» → مشاهده تک تک موارد حافظه\n"
          . "• «➕ افزودن به حافظه» → اضافه کردن اطلاعات جدید با انتخاب اهمیت\n"
          . "• «🗑️ پاک کردن حافظه» → پاک کردن همه (با تایید نهایی)\n"
          . "• «🚫 غیرفعال کردن حافظه» → خاموش کردن کامل\n\n"
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