<?php

namespace Modules\Bot\Handlers;

use Modules\AI\AIService;
use Modules\AI\UploadService;
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
            if ($update->isCallback() && is_string($callbackData) && str_starts_with($callbackData, 'edit_select_model_')) {
                $modelId = (int) str_replace('edit_select_model_', '', $callbackData);
                $this->saveModelAndAskPhotos($chatId, $userId, $modelId);
                return;
            }

            // Done button — MUST be checked BEFORE state check
            if ($callbackData === 'edit_photos_done') {
                $this->downloadAllAndAskPrompt($chatId, $userId);
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
                'callback_data' => "edit_select_model_{$model['id']}"
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
     * Sends a message only for the FIRST photo to avoid spam for media groups.
     * When user clicks "انجام شد", all photos are downloaded at once.
     */
    private function storeFileId(int $chatId, int $userId, string $fileId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();

        try {
            // Read current state
            $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
            $extra = json_decode($stateData['extra_data'] ?? '{}', true);
            $fileIds = $extra['file_ids'] ?? [];
            $wasEmpty = empty($fileIds);

            if (count($fileIds) >= 5) {
                $this->baleClient->sendMessage($chatId, "⚠️ حداکثر ۵ عکس مجاز است.", $this->getDoneKeyboard());
                return;
            }

            if (in_array($fileId, $fileIds)) {
                return;
            }

            $fileIds[] = $fileId;
            $extra['file_ids'] = $fileIds;

            // Write back
            $db->query("UPDATE bot_state SET extra_data = ? WHERE user_id = ?", [json_encode($extra), $internalId]);

            // Send message only for the first photo
            if ($wasEmpty) {
                $this->baleClient->sendMessage($chatId, "✅ عکس دریافت شد. می‌توانید عکس‌های بیشتری ارسال کنید یا دکمه ✅ انجام شد را بزنید.", $this->getDoneKeyboard());
            }
        } catch (\Throwable $e) {
            // Silent fail
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

        // Download all photos now using direct curl (bypass broken downloadFile)
        $paths = [];
        $failedCount = 0;
        $token = \Core\Config::get('BALE_BOT_TOKEN');

        foreach ($fileIds as $i => $fileId) {
            // In Bale Bot API, the file_path IS the file_id for new-style file identifiers
            // We download directly using the file_id as the path
            $downloadUrl = "https://tapi.bale.ai/file/bot{$token}/{$fileId}";

            $ch = curl_init($downloadUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $imageBinary = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $downloadedSize = strlen($imageBinary ?? '');
            curl_close($ch);

            if ($httpCode !== 200 || empty($imageBinary) || $downloadedSize < 1000) {
                error_log("Img2ImgHandler: Failed to download photo, HTTP=$httpCode, downloaded=$downloadedSize, fileId=$fileId");
                $failedCount++;
                continue;
            }

            $tmpFilename = 'edit_' . $internalId . '_' . time() . '_' . $i . '.jpg';
            $tmpPath = $this->uploadDir . $tmpFilename;
            file_put_contents($tmpPath, $imageBinary);
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
        $uploadService = new UploadService();
        $isMetisai = strtolower($model['provider'] ?? '') === 'metisai';

        foreach ($paths as $photoPath) {
            if (!file_exists($photoPath)) continue;
            $imageData = file_get_contents($photoPath);
            
            // For MetisAI: save locally and pass public URL
            // For GapGPT: pass base64
            if ($isMetisai) {
                $imageUrl = $uploadService->saveImage($internalId, $imageData);
                if (!$imageUrl) {
                    $imageUrl = base64_encode($imageData); // fallback
                }
            } else {
                $imageUrl = base64_encode($imageData);
            }
            
            @unlink($photoPath);

            $result = $aiService->generate([
                'model'      => $model['name'],
                'prompt'     => $prompt,
                'image'      => $imageUrl,
                'provider'   => $model['provider'] ?? '',
                'model_data' => $model,
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