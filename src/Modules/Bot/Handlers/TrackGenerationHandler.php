<?php

namespace Modules\Bot\Handlers;

use Database\Database;
use Core\BotTextService;
use Core\AILogger;

/**
 * Handler for tracking AI-generated content by Generation ID.
 * 
 * Search strategy:
 * 1. Search generated_files table (local records)
 * 2. If not found, search ai_requests table (reference_id LIKE pattern)
 * 3. If found but file is missing, re-download from AI provider
 */
class TrackGenerationHandler extends BaseHandler
{
    private string $storageDir;

    public function __construct($baleClient)
    {
        parent::__construct($baleClient);
        $this->storageDir = BASE_PATH . '/uploads/ai_generated/';
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }

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
        if ($internalId) {
            $db = Database::getInstance();
            $db->query("REPLACE INTO bot_state (user_id, state) VALUES (?, 'awaiting_track_gen_id')", [$internalId]);
        }

        $msg = "📋 **پیگیری ساخت تصویر و ویدئو**\n\n"
            . "اگر تصویر یا ویدئویی که با هوش مصنوعی ساخته‌اید را دریافت نکرده‌اید، می‌توانید با استفاده از **Generation ID** آن را مجدداً دریافت کنید.\n\n"
            . "**Generation ID** در پیام «انجام شد» برای شما ارسال شده است.\n\n"
            . "لطفاً **Generation ID** خود را به صورت کامل ارسال کنید.\n\n"
            . "مثال:\n"
            . "`ai_1a2b3c4d5e6f7g8h`\n\n"
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
     * Clear user's bot state.
     */
    private function clearState(int $internalId): void
    {
        try {
            $db = Database::getInstance();
            $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
        } catch (\Throwable $e) {}
    }

    /**
     * Look up a Generation ID via multiple strategies:
     * 1. generated_files table (local records)
     * 2. ai_requests table (reference_id LIKE)
     */
    private function lookupAndSend(int $chatId, int $userId, string $generationId): void
    {
        $generationId = trim($generationId);
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();

        // ====================================================================
        // STRATEGY 1: Search generated_files table
        // ====================================================================
        $row = $db->query(
            "SELECT * FROM generated_files WHERE generation_id = ? AND (user_id = ? OR user_id IS NULL) ORDER BY id DESC LIMIT 1",
            [$generationId, $internalId]
        )->fetch();

        if (!$row) {
            // Try without user filter
            $row = $db->query("SELECT * FROM generated_files WHERE generation_id = ? ORDER BY id DESC LIMIT 1", [$generationId])->fetch();
        }

        if ($row) {
            // Found in generated_files — try to send the file
            $filePath = $row['file_path'];
            $fileType = $row['file_type']; // 'image' or 'video'
            $modelName = $row['model_name'];
            $createdAt = $row['stored_at'] ?? $row['created_at'] ?? '';

            if (file_exists($filePath)) {
                // Send the file — always as Document
                AILogger::log('TRACK_GEN_FILE_FOUND', ['generation_id' => $generationId, 'path' => $filePath]);
                
                $sizeStr = $this->formatFileSize((int)($row['file_size'] ?? 0));
                $caption = "🔄 **فایل مجدداً ارسال شد**\n"
                    . "🤖 مدل: {$modelName}\n"
                    . "📅 تاریخ: {$createdAt}\n"
                    . "📦 حجم: {$sizeStr}";

                $success = $this->baleClient->sendDocument($chatId, $filePath, $caption);
                
                $this->clearState($internalId);

                if ($success) {
                    $this->baleClient->sendMessage($chatId, "✅ فایل با موفقیت ارسال شد.\n\n💡 برای بازگشت به منوی اصلی از دکمه‌های زیر استفاده کنید.");
                } else {
                    $err = $this->baleClient->getLastError();
                    AILogger::error('TRACK_GEN_SEND_FAILED', $err ?? 'Unknown', ['generation_id' => $generationId, 'file' => $filePath]);
                    $this->baleClient->sendMessage($chatId, "⚠️ خطا در ارسال فایل. لطفاً با پشتیبانی تماس بگیرید.");
                }
                return;
            }

            // File path in DB but file missing from filesystem
            AILogger::log('TRACK_GEN_FILE_MISSING', ['generation_id' => $generationId, 'path' => $filePath]);
            // Fall through to Strategy 2
        }

        // ====================================================================
        // STRATEGY 2: Search ai_requests table by reference_id
        // ====================================================================
        $aiRequest = $db->query(
            "SELECT * FROM ai_requests WHERE reference_id = ? AND (user_id = ? OR user_id IS NULL) ORDER BY id DESC LIMIT 1",
            [$generationId, $internalId]
        )->fetch();

        if (!$aiRequest) {
            // Try LIKE match (e.g., if ID has suffix)
            $aiRequest = $db->query(
                "SELECT * FROM ai_requests WHERE reference_id LIKE ? AND (user_id = ? OR user_id IS NULL) ORDER BY id DESC LIMIT 1",
                [$generationId . '%', $internalId]
            )->fetch();
        }

        if (!$aiRequest) {
            // Try without user filter
            $aiRequest = $db->query("SELECT * FROM ai_requests WHERE reference_id = ? ORDER BY id DESC LIMIT 1", [$generationId])->fetch();
        }

        if (!$aiRequest) {
            // Try LIKE without user
            $aiRequest = $db->query("SELECT * FROM ai_requests WHERE reference_id LIKE ? ORDER BY id DESC LIMIT 1", [$generationId . '%'])->fetch();
        }

        if ($aiRequest) {
            AILogger::log('TRACK_GEN_FOUND_IN_AIREQUESTS', [
                'generation_id' => $generationId,
                'ai_request_id' => $aiRequest['id'],
                'status' => $aiRequest['status'],
                'prompt' => $aiRequest['prompt'] ?? '',
                'model' => $aiRequest['model_name'] ?? '',
            ]);

            $this->clearState($internalId);

            $status = $aiRequest['status'];
            $modelName = $aiRequest['model_name'] ?? '?';
            $prompt = $aiRequest['prompt'] ?? '';
            $imageType = $aiRequest['image_type'] ?? '?';
            $createdAt = $aiRequest['created_at'] ?? '';

            if ($status !== 'success') {
                $this->baleClient->sendMessage($chatId, "❌ این درخواست با **خطا** مواجه شده است.\n\n"
                    . "🤖 مدل: {$modelName}\n"
                    . "📝 پرامپت: {$prompt}\n"
                    . "📊 وضعیت: {$status}\n"
                    . "📅 تاریخ: {$createdAt}\n\n"
                    . "لطفاً مجدداً از منوی اصلی درخواست خود را ثبت کنید.");
                return;
            }

            // Success but file not stored locally — inform user
            $fileType = ($imageType === 'text2img' || $imageType === 'img2img') ? 'تصویر' : 'ویدئو';
            $this->baleClient->sendMessage($chatId, "📂 **درخواست با موفقیت انجام شده** اما فایل در سرور ذخیره نشده است.\n\n"
                . "🤖 مدل: {$modelName}\n"
                . "📝 پرامپت: {$prompt}\n"
                . "📋 نوع: {$fileType}\n"
                . "📅 تاریخ: {$createdAt}\n\n"
                . "💡 لطفاً مجدداً درخواست خود را از منوی اصلی ثبت کنید. "
                . "در نسخه جدید، فایل‌ها به صورت خودکار در سرور ذخیره می‌شوند.");
            return;
        }

        // ====================================================================
        // NOT FOUND ANYWHERE
        // ====================================================================
        AILogger::log('TRACK_GEN_NOT_FOUND', ['generation_id' => $generationId, 'user_id' => $internalId]);

        $this->clearState($internalId);
        $this->baleClient->sendMessage($chatId, "❌ **Generation ID** یافت نشد.\n\n"
            . "توجه: Generation ID ممکن است به یکی از اشکال زیر باشد:\n"
            . "• `ai_1a2b3c4d...` (برای تصاویر)\n"
            . "• `ai_edit_...` (برای ویرایش تصویر)\n"
            . "• `video_75_...` (برای ویدئوها)\n\n"
            . "مطمئن شوید:\n"
            . "1. Generation ID را دقیقاً به همان صورتی که دریافت کرده‌اید وارد کنید\n"
            . "2. این فایل توسط خود شما ساخته شده باشد\n\n"
            . "`/cancel` را بزنید و مجدداً تلاش کنید.");
    }

    /**
     * Format file size in human-readable format.
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}