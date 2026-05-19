<?php

namespace Modules\Bot\Handlers;

use Database\Database;
use Core\BotTextService;

/**
 * Handler for tracking AI-generated content by Generation ID.
 * User sends a Generation ID, bot looks it up and resends the file.
 */
class TrackGenerationHandler extends BaseHandler
{
    public function handle($update): void
    {
        try {
            $chatId = $update->getChatId();
            $userId = $update->getUserId();
            $text = trim($update->getText() ?? '');
            $callbackData = $update->getCallbackData();

            // Entry callback
            if ($callbackData === 'track_generation') {
                $this->showInstructions($chatId, $userId);
                return;
            }

            // If user is in tracking state
            $state = $this->getUserState($userId);
            if ($state === 'awaiting_track_gen_id') {
                if (empty($text)) {
                    $this->baleClient->sendMessage($chatId, BotTextService::get('track_gen_enter_id'));
                    return;
                }
                $this->lookupAndSend($chatId, $userId, $text);
                return;
            }
            
            // Fallback
            $this->baleClient->sendMessage($chatId, "🏠 منوی اصلی");
            
        } catch (\Throwable $e) {
            error_log("TrackGenerationHandler FATAL: " . $e->getMessage());
            if (isset($chatId)) {
                $this->baleClient->sendMessage($chatId, "⚠️ خطایی رخ داد. مجدداً تلاش کنید.");
            }
        }
    }

    /**
     * Show instructions for using the tracking feature.
     */
    private function showInstructions(int $chatId, int $userId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();
        $db->query("REPLACE INTO bot_state (user_id, state) VALUES (?, 'awaiting_track_gen_id')", [$internalId]);

        $msg = "📋 **پیگیری ساخت تصویر و ویدئو**\n\n"
            . "اگر تصویر یا ویدئویی که با هوش مصنوعی ساخته‌اید را دریافت نکرده‌اید، می‌توانید با استفاده از **Generation ID** آن را مجدداً دریافت کنید.\n\n"
            . "**Generation ID** در پیام «انجام شد» برای شما ارسال شده است.\n\n"
            . "لطفاً **Generation ID** خود را به صورت کامل ارسال کنید.\n\n"
            . "مثال:\n"
            . "`gen-1779168130-DJxjevFIEDuYvStohhfo`\n\n"
            . "`/cancel` برای لغو";

        $this->baleClient->sendMessage($chatId, $msg);
    }

    /**
     * Resolve Bale user ID to internal user ID.
     */
    private function resolveUserId(int $baleUserId): ?int
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT id FROM users WHERE bale_user_id = ?", [$baleUserId]);
            $row = $stmt->fetch();
            return $row ? (int) $row['id'] : null;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Get user's current state.
     */
    private function getUserState(int $baleUserId): ?string
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT bs.state FROM bot_state bs JOIN users u ON bs.user_id = u.id WHERE u.bale_user_id = ?", [$baleUserId]);
            $row = $stmt->fetch();
            return $row['state'] ?? null;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Look up a Generation ID in the generated_files table and resend the file to user.
     */
    private function lookupAndSend(int $chatId, int $userId, string $generationId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();

        // Look up the file in our database
        $row = $db->query("SELECT * FROM generated_files WHERE generation_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1", [$generationId, $internalId])->fetch();

        if (!$row) {
            // Also try without user_id filter (some files may be cross-user)
            $row = $db->query("SELECT * FROM generated_files WHERE generation_id = ? ORDER BY id DESC LIMIT 1", [$generationId])->fetch();
        }

        if (!$row) {
            $this->baleClient->sendMessage($chatId, "❌ **Generation ID** یافت نشد.\n\n"
                . "مطمئن شوید:\n"
                . "1. Generation ID دقیقاً به همان صورتی که در پیام «انجام شد» ارسال کرده بودید را وارد کنید\n"
                . "2. مطمئن شوید این فایل توسط خود شما ساخته شده باشد\n"
                . "3. اگر مشکل باقی ماند، ممکن است فایل از سرور پاک شده باشد\n\n"
                . "`/cancel` را بزنید و مجدداً تلاش کنید.");
            
            // Clear state
            $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
            return;
        }

        $filePath = $row['file_path'];
        $fileType = $row['file_type']; // 'image' or 'video'
        $modelName = $row['model_name'];
        $prompt = $row['prompt'] ?? '';
        $createdAt = $row['created_at'];
        $fileSize = (int)$row['file_size'];

        // Check if file still exists
        if (!file_exists($filePath)) {
            $this->baleClient->sendMessage($chatId, "❌ فایل مورد نظر دیگر روی سرور وجود ندارد. ممکن است توسط مدیر پاک شده باشد.");
            $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
            return;
        }

        // Send the file based on type
        $caption = "🔄 **فایل مجدداً ارسال شد**\n"
            . "🤖 مدل: {$modelName}\n"
            . "📅 تاریخ: {$createdAt}\n"
            . "📦 حجم: {$fileSize} بایت";

        $success = false;

        if ($fileType === 'image') {
            $success = $this->baleClient->sendPhotoFile($chatId, $filePath, $caption);
        } elseif ($fileType === 'video') {
            $success = $this->baleClient->sendVideo($chatId, $filePath, $caption);
            if (!$success) {
                $success = $this->baleClient->sendDocument($chatId, $filePath, $caption);
            }
        } else {
            $success = $this->baleClient->sendDocument($chatId, $filePath, $caption);
        }

        if ($success) {
            $this->baleClient->sendMessage($chatId, "✅ فایل با موفقیت ارسال شد.\n\n"
                . "💡 می‌توانید این فایل را ذخیره کنید.\n\n"
                . "`/cancel` برای بازگشت به منوی اصلی");
        } else {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در ارسال فایل. لطفاً با پشتیبانی تماس بگیرید.");
        }

        // Clear tracking state
        $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
    }
}