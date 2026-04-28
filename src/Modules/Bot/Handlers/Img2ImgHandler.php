<?php

namespace Modules\Bot\Handlers;

use Modules\AI\AIService;
use Modules\Bot\CreditService;
use Modules\Bot\Models\User;
use Database\Database;
use Database\Logger;

class Img2ImgHandler extends BaseHandler
{
    private string $uploadDir;

    public function __construct($baleClient)
    {
        parent::__construct($baleClient);
        $this->uploadDir = BASE_PATH . '/uploads/tmp/';
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }
    }

    public function handle($update): void
    {
        try {
            $chatId = $update->getChatId();
            $userId = $update->getUserId();
            $text = $update->getText();
            $callbackData = $update->getCallbackData();

            $state = $this->getUserState($userId);

            // AI processing lock
            if ($state === 'ai_processing') {
                if ($text === '/cancel') {
                    $this->baleClient->sendMessage($chatId, "⚠️ درخواست شما در حال پردازش است. در صورت لغو، هزینه عودت داده نمی‌شود و خروجی پس از آماده شدن ارسال خواهد شد.");
                    return;
                }
                $this->baleClient->sendMessage($chatId, "⏳ لطفاً صبور باشید...");
                return;
            }

            // Entry
            if ($callbackData === 'edit_image' || $text === '🖼 ویرایش عکس') {
                $this->showModelSelection($chatId, $userId);
                return;
            }

            // Model selection
            if ($update->isCallback() && is_string($callbackData) && str_starts_with($callbackData, 'select_model_')) {
                $modelId = (int) str_replace('select_model_', '', $callbackData);
                $this->saveModelAndAskPhotos($chatId, $userId, $modelId);
                return;
            }

            // Photo upload — store only file_id, show Done button
            if ($state === 'awaiting_edit_photo') {
                if ($update->hasPhoto()) {
                    $this->storeFileId($chatId, $userId, $update->getPhotoFileId());
                } else {
                    $this->baleClient->sendMessage($chatId, "📸 لطفاً عکس ارسال کنید (حداکثر ۵)\nسپس دکمه ✅ انجام شد را بزنید:", $this->getDoneKeyboard());
                }
                return;
            }

            // Done button
            if ($callbackData === 'edit_photos_done') {
                $this->downloadAllAndAskPrompt($chatId, $userId);
                return;
            }

            // Prompt
            if ($state === 'awaiting_edit_prompt') {
                $this->processEditRequest($chatId, $userId, $text);
                return;
            }

            $this->baleClient->sendMessage($chatId, "🤖 لطفاً یکی از گزینه‌های منو را انتخاب کنید:", $this->getPersistentKeyboard());
        } catch (\Throwable $e) {
            error_log("Img2ImgHandler FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            Logger::error('Img2ImgHandler exception', ['user_id' => $userId ?? 0, 'error' => $e->getMessage()]);
            if (isset($chatId)) {
                $this->baleClient->sendMessage($chatId, "⚠️ خطایی رخ داد. مجدداً تلاش کنید.");
            }
        }
    }

    private function resolveUserId(int $baleUserId): ?int
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT id FROM users WHERE bale_user_id = ?", [$baleUserId]);
            $row = $stmt->fetch();
            return $row ? (int) $row['id'] : null;
        } catch (\Throwable $e) { return null; }
    }

    private function showModelSelection(int $chatId, int $userId): void
    {
        $db = Database::getInstance();
        $models = $db->query("SELECT id, name, cost_per_image FROM ai_models WHERE is_active = 1")->fetchAll();

        if (empty($models)) {
            $this->baleClient->sendMessage($chatId, "❌ هیچ مدل فعالی یافت نشد.");
            return;
        }

        $keyboard = ['inline_keyboard' => []];
        foreach ($models as $model) {
            $keyboard['inline_keyboard'][] = [[
                'text' => "🖼 {$model['name']} ({$model['cost_per_image']} اعتبار)",
                'callback_data' => "select_model_{$model['id']}"
            ]];
        }

        $internalId = $this->resolveUserId($userId);
        $db->query("REPLACE INTO bot_state (user_id, state) VALUES (?, 'selecting_model_edit')", [$internalId]);
        $this->baleClient->sendMessage($chatId, "🎯 ابتدا مدل مورد نظر را انتخاب کنید:", $keyboard);
    }

    private function saveModelAndAskPhotos(int $chatId, int $userId, int $modelId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();
        $db->query(
            "UPDATE bot_state SET state = 'awaiting_edit_photo', extra_data = ? WHERE user_id = ?",
            [json_encode(['model_id' => $modelId, 'file_ids' => []]), $internalId]
        );
        $this->baleClient->sendMessage($chatId, "📸 مدل انتخاب شد. عکس‌ها را ارسال کنید (حداکثر ۵)\nسپس دکمه ✅ انجام شد را بزنید:", $this->getDoneKeyboard());
    }

    private function getDoneKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '✅ انجام شد', 'callback_data' => 'edit_photos_done']]
            ]
        ];
    }

    /**
     * Store only the file_id in DB (fast, no download).
     * Photos are downloaded ALL AT ONCE when user clicks "انجام شد".
     */
    private function storeFileId(int $chatId, int $userId, string $fileId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();
        $conn = $db->getConnection();

        try {
            // Use transaction + FOR UPDATE for safe concurrent access
            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT extra_data FROM bot_state WHERE user_id = ? FOR UPDATE");
            $stmt->execute([$internalId]);
            $stateData = $stmt->fetch();
            $extra = json_decode($stateData['extra_data'] ?? '{}', true);
            $fileIds = $extra['file_ids'] ?? [];

            if (count($fileIds) >= 5) {
                $conn->rollBack();
                $this->baleClient->sendMessage($chatId, "⚠️ حداکثر ۵ عکس مجاز است. دکمه ✅ انجام شد را بزنید.", $this->getDoneKeyboard());
                return;
            }

            if (in_array($fileId, $fileIds)) {
                $conn->rollBack();
                $this->baleClient->sendMessage($chatId, "⚠️ این عکس قبلاً ارسال شده.");
                return;
            }

            $fileIds[] = $fileId;
            $extra['file_ids'] = $fileIds;

            $stmt = $conn->prepare("UPDATE bot_state SET extra_data = ? WHERE user_id = ?");
            $stmt->execute([json_encode($extra), $internalId]);
            $conn->commit();

            $remaining = 5 - count($fileIds);
            $msg = "✅ عکس " . count($fileIds) . " دریافت شد.\n";
            $msg .= ($remaining > 0) ? "تا {$remaining} عکس دیگر می‌توانید ارسال کنید.\n" : "حداکثر تعداد رسید.\n";
            $msg .= "سپس دکمه ✅ انجام شد را بزنید.";
            $this->baleClient->sendMessage($chatId, $msg, $this->getDoneKeyboard());
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در ذخیره عکس.");
        }
    }

    /**
     * Download ALL photos at once when user clicks Done.
     * This avoids race conditions from separate PHP requests.
     */
    private function downloadAllAndAskPrompt(int $chatId, int $userId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();

        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $fileIds = $extra['file_ids'] ?? [];

        if (count($fileIds) < 1) {
            $this->baleClient->sendMessage($chatId, "⚠️ حداقل ۱ عکس ارسال کنید.", $this->getDoneKeyboard());
            return;
        }

        $this->baleClient->sendMessage($chatId, "⏳ در حال دریافت عکس‌ها از بله...");

        // Download all photos now
        $paths = [];
        $failedCount = 0;

        foreach ($fileIds as $i => $fileId) {
            $fileInfo = $this->baleClient->getFile($fileId);
            if (!$fileInfo || empty($fileInfo['file_path'])) {
                $failedCount++;
                continue;
            }

            $imageData = $this->baleClient->downloadFile($fileInfo['file_path']);
            if (!$imageData) {
                $failedCount++;
                continue;
            }

            $tmpFilename = 'edit_' . $internalId . '_' . time() . '_' . $i . '.jpg';
            $tmpPath = $this->uploadDir . $tmpFilename;
            file_put_contents($tmpPath, $imageData);
            $paths[] = $tmpPath;
        }

        if (empty($paths)) {
            $this->baleClient->sendMessage($chatId, "⚠️ هیچ‌کدام از عکس‌ها قابل دریافت نبود.");
            return;
        }

        // Store paths and advance state
        $extra['photo_paths'] = $paths;
        unset($extra['file_ids']);

        $db->query(
            "UPDATE bot_state SET state = 'awaiting_edit_prompt', extra_data = ? WHERE user_id = ?",
            [json_encode($extra), $internalId]
        );

        $sentMsg = "✏️ " . count($paths) . " عکس دریافت شد.";
        if ($failedCount > 0) {
            $sentMsg .= " {$failedCount} عکس قابل دریافت نبود.";
        }
        $sentMsg .= " متن تغییرات (Prompt) را بنویسید:";
        $this->baleClient->sendMessage($chatId, $sentMsg);
    }

    private function processEditRequest(int $chatId, int $userId, string $prompt): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();

        $db->query("UPDATE bot_state SET state = 'ai_processing' WHERE user_id = ?", [$internalId]);

        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);

        $modelId = (int)($extra['model_id'] ?? 0);
        $paths = $extra['photo_paths'] ?? [];

        if (empty($modelId) || empty($paths)) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "❌ خطا در بازیابی اطلاعات. دوباره شروع کنید.");
            return;
        }

        $aiService = new AIService();
        $model = $aiService->getActiveModelById($modelId);

        if (!$model) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "❌ مدل یافت نشد.");
            return;
        }

        $cost = (int) $model['cost_per_image'];
        if (!CreditService::hasEnoughCredit($internalId, $cost)) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "❌ اعتبار کافی ندارید.");
            return;
        }

        $referenceId = 'ai_edit_' . bin2hex(random_bytes(8));

        if (!CreditService::deduct($internalId, $cost, $referenceId)) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در کسر اعتبار.");
            return;
        }

        $this->baleClient->sendMessage($chatId, "⏳ در حال پردازش ویرایش عکس... لطفاً صبور باشید.");

        // Process all photos
        $allImages = [];
        $hasError = false;
        $errorMsg = '';

        foreach ($paths as $photoPath) {
            if (!file_exists($photoPath)) continue;
            $imageData = file_get_contents($photoPath);
            $photoBase64 = base64_encode($imageData);
            @unlink($photoPath);

            $result = $aiService->generate([
                'model'  => $model['name'],
                'prompt' => $prompt,
                'image'  => $photoBase64
            ]);

            if (isset($result['error'])) {
                $hasError = true;
                $errorMsg = $result['error'];
                break;
            }

            if (!empty($result['images'])) {
                $allImages = array_merge($allImages, $result['images']);
            }
        }

        $imageType = 'img2img';

        if ($hasError && empty($allImages)) {
            $db->query(
                "INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, ?, 'failed', ?)",
                [$internalId, $modelId, $prompt, $imageType, $referenceId]
            );
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "⚠️ خطا: " . $errorMsg);
            return;
        }

        $db->query(
            "INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, ?, 'success', ?)",
            [$internalId, $modelId, $prompt, $imageType, $referenceId]
        );

        foreach ($allImages as $url) {
            $this->baleClient->sendPhoto($chatId, $url, "✅ ویرایش تصویر انجام شد\n💎 هزینه: {$cost} اعتبار");
        }
        $this->clearUserState($internalId);
        $this->baleClient->sendMessage($chatId, "✨ انجام شد.", $this->getMainMenuInlineKeyboard());
    }

    private function getUserState(int $baleUserId): ?string
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query(
                "SELECT bs.state FROM bot_state bs 
                 JOIN users u ON bs.user_id = u.id 
                 WHERE u.bale_user_id = ?",
                [$baleUserId]
            );
            $row = $stmt->fetch();
            return $row['state'] ?? null;
        } catch (\Throwable $e) { return null; }
    }

    private function clearUserState(int $internalId): void
    {
        try {
            $db = Database::getInstance();
            $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
            if ($stateData) {
                $extra = json_decode($stateData['extra_data'] ?? '{}', true);
                foreach (($extra['photo_paths'] ?? []) as $path) {
                    if (file_exists($path)) @unlink($path);
                }
            }
            $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
        } catch (\Throwable $e) {}
    }

    private function getMainMenuInlineKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '🎨 ساخت تصویر', 'callback_data' => 'generate_image'], ['text' => '🖼 ویرایش عکس', 'callback_data' => 'edit_image']],
                [['text' => '👤 حساب من', 'callback_data' => 'account'], ['text' => '💳 شارژ اعتبار', 'callback_data' => 'buy_credit']]
            ]
        ];
    }

    private function getPersistentKeyboard(): array
    {
        return [
            'keyboard' => [[['text' => '/cancel'], ['text' => "منو اصلی"]]],
            'resize_keyboard' => true
        ];
    }
}