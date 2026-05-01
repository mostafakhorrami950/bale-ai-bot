<?php

namespace Modules\Bot\Handlers;

use Modules\AI\AIService;
use Modules\Bot\CreditService;
use Database\Database;
use Database\Logger;

class Img2ImgHandler extends BaseHandler
{
    private string $uploadDir;
    private int $maxPhotos = 3;

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
                    $this->baleClient->sendMessage($chatId, '⚠️ درخواست شما در حال پردازش است. در صورت لغو، هزینه عودت داده نمی‌شود و خروجی پس از آماده شدن ارسال خواهد شد.');
                    return;
                }
                $this->baleClient->sendMessage($chatId, '⏳ لطفاً صبور باشید...');
                return;
            }

            // Entry
            if ($callbackData === 'edit_image' || $text === '🖼 ویرایش عکس') {
                $this->showModelSelection($chatId, $userId);
                return;
            }

            // Model selection callback
            if ($update->isCallback() && is_string($callbackData) && str_starts_with($callbackData, 'edit_select_model_')) {
                $modelId = (int) str_replace('edit_select_model_', '', $callbackData);
                $this->saveModelAndAskPhotos($chatId, $userId, $modelId);
                return;
            }

            // Done button — process all collected photos
            if ($callbackData === 'edit_photos_done') {
                $this->downloadAllAndAskPrompt($chatId, $userId);
                return;
            }

            // Photo upload — store file_id
            if ($state === 'awaiting_edit_photo') {
                if ($update->hasPhoto()) {
                    $this->storeFileId($chatId, $userId, $update->getPhotoFileId());
                } else {
                    $this->baleClient->sendMessage($chatId, "📸 لطفاً عکس ارسال کنید (حداکثر {$this->maxPhotos})\nسپس دکمه ✅ انجام شد را بزنید:", $this->getDoneKeyboard());
                }
                return;
            }

            // Prompt received
            if ($state === 'awaiting_edit_prompt') {
                $this->processEditRequest($chatId, $userId, $text);
                return;
            }

            $this->baleClient->sendMessage($chatId, '🤖 لطفاً یکی از گزینه‌های منو را انتخاب کنید:', $this->getPersistentKeyboard());
        } catch (\Throwable $e) {
            error_log("Img2ImgHandler FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            Logger::error('Img2ImgHandler exception', ['user_id' => $userId ?? 0, 'error' => $e->getMessage()]);
            if (isset($chatId)) {
                $this->baleClient->sendMessage($chatId, '⚠️ خطایی رخ داد. مجدداً تلاش کنید.');
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
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function showModelSelection(int $chatId, int $userId): void
    {
        $db = Database::getInstance();
        $models = $db->query("SELECT id, name, cost_per_edit AS cost_per_image FROM ai_edit_models WHERE is_active = 1")->fetchAll();

        if (empty($models)) {
            $this->baleClient->sendMessage($chatId, '❌ هیچ مدل فعالی یافت نشد.');
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
        $this->baleClient->sendMessage($chatId, '🎯 ابتدا مدل مورد نظر را انتخاب کنید:', $keyboard);
    }

    private function saveModelAndAskPhotos(int $chatId, int $userId, int $modelId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();
        $db->query(
            "UPDATE bot_state SET state = 'awaiting_edit_photo', extra_data = ? WHERE user_id = ?",
            [json_encode(['model_id' => $modelId, 'file_ids' => []]), $internalId]
        );
        $this->baleClient->sendMessage($chatId, "📸 مدل انتخاب شد. عکس‌ها را ارسال کنید (حداکثر {$this->maxPhotos})\nسپس دکمه ✅ انجام شد را بزنید:", $this->getDoneKeyboard());
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
     * Store file_id in DB (no download yet).
     * Server-side validation: max $this->maxPhotos photos.
     */
    private function storeFileId(int $chatId, int $userId, string $fileId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();

        try {
            $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
            $extra = json_decode($stateData['extra_data'] ?? '{}', true);
            $fileIds = $extra['file_ids'] ?? [];
            $wasEmpty = empty($fileIds);

            // Server-side limit validation
            if (count($fileIds) >= $this->maxPhotos) {
                $this->baleClient->sendMessage($chatId, "⚠️ حداکثر {$this->maxPhotos} عکس مجاز است.", $this->getDoneKeyboard());
                return;
            }

            // Deduplicate
            if (in_array($fileId, $fileIds)) {
                return;
            }

            $fileIds[] = $fileId;
            $extra['file_ids'] = $fileIds;

            $db->query("UPDATE bot_state SET extra_data = ? WHERE user_id = ?", [json_encode($extra), $internalId]);

            if ($wasEmpty) {
                $this->baleClient->sendMessage($chatId, "✅ عکس دریافت شد. می‌توانید عکس‌های بیشتری ارسال کنید یا دکمه ✅ انجام شد را بزنید.", $this->getDoneKeyboard());
            }
        } catch (\Throwable $e) {
            Logger::error('storeFileId', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Download ALL photos at once when user clicks Done.
     */
    private function downloadAllAndAskPrompt(int $chatId, int $userId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();

        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $fileIds = $extra['file_ids'] ?? [];

        if (count($fileIds) < 1) {
            $this->baleClient->sendMessage($chatId, '⚠️ حداقل ۱ عکس ارسال کنید.', $this->getDoneKeyboard());
            return;
        }

        $this->baleClient->sendMessage($chatId, '⏳ در حال دریافت عکس‌ها از بله...');

        $paths = [];
        $failedCount = 0;
        $token = \Core\Config::get('BALE_BOT_TOKEN');

        foreach ($fileIds as $i => $fileId) {
            $downloadUrl = "https://tapi.bale.ai/file/bot{$token}/{$fileId}";

            $ch = curl_init($downloadUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            $imageBinary = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $downloadedSize = strlen($imageBinary ?? '');
            curl_close($ch);

            if ($httpCode !== 200 || empty($imageBinary) || $downloadedSize < 1000) {
                $failedCount++;
                continue;
            }

            $tmpFilename = 'edit_' . $internalId . '_' . time() . '_' . $i . '.jpg';
            $tmpPath = $this->uploadDir . $tmpFilename;
            file_put_contents($tmpPath, $imageBinary);
            $paths[] = $tmpPath;
        }

        if (empty($paths)) {
            $this->baleClient->sendMessage($chatId, '⚠️ هیچ‌کدام از عکس‌ها قابل دریافت نبود.');
            return;
        }

        $extra['photo_paths'] = $paths;
        unset($extra['file_ids']);

        $db->query(
            "UPDATE bot_state SET state = 'awaiting_edit_prompt', extra_data = ? WHERE user_id = ?",
            [json_encode($extra), $internalId]
        );

        $msg = '✏️ ' . count($paths) . ' عکس دریافت شد.';
        if ($failedCount > 0) {
            $msg .= " {$failedCount} عکس قابل دریافت نبود.";
        }
        $msg .= ' متن تغییرات (Prompt) را بنویسید:';
        $this->baleClient->sendMessage($chatId, $msg);
    }

    /**
     * Process edit request.
     * ALL photos are sent to OpenRouter in ONE API call via the `images` array.
     * The model sees all photos in context and returns ONE output image only.
     */
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
            $this->baleClient->sendMessage($chatId, '❌ خطا در بازیابی اطلاعات. دوباره شروع کنید.');
            return;
        }

        $aiService = new AIService();
        $model = $aiService->getActiveModelById($modelId);

        if (!$model) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, '❌ مدل یافت نشد.');
            return;
        }

        $cost = (int) $model['cost_per_image'];

        // Credit check — single charge for one AI generation with all photos
        if (!CreditService::hasEnoughCredit($internalId, $cost)) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "❌ اعتبار کافی ندارید (نیاز به {$cost} اعتبار).");
            return;
        }

        $referenceId = 'ai_edit_' . bin2hex(random_bytes(8));

        if (!CreditService::deduct($internalId, $cost, $referenceId)) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, '⚠️ خطا در کسر اعتبار.');
            return;
        }

        $this->baleClient->sendMessage($chatId, '⏳ در حال پردازش ویرایش عکس... لطفاً صبور باشید.');

        // Convert ALL photos to data URIs
        $imageDataUris = [];
        $failedPaths = [];

        foreach ($paths as $photoPath) {
            if (!file_exists($photoPath)) {
                continue;
            }
            $photoData = file_get_contents($photoPath);
            @unlink($photoPath);

            if (empty($photoData) || strlen($photoData) < 500) {
                $failedPaths[] = $photoPath;
                continue;
            }

            $mime = 'image/jpeg';
            $first = substr($photoData, 0, 4);
            if (str_starts_with($first, "\x89PNG")) $mime = 'image/png';
            elseif (str_starts_with($first, "\xff\xd8")) $mime = 'image/jpeg';

            $imageDataUris[] = 'data:' . $mime . ';base64,' . base64_encode($photoData);
        }

        if (empty($imageDataUris)) {
            $db->query(
                "INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, ?, 'failed', ?)",
                [$internalId, $modelId, $prompt, 'img2img', $referenceId]
            );
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, '⚠️ تصاویر معتبر نیستند.');
            return;
        }

        // Single API call with ALL images in the `images` array
        // OpenRouter/Gemini receives all photos as content parts in one message
        // and produces ONE image output based on all inputs
        $result = $aiService->generate([
            'model'      => $model['name'],
            'prompt'     => $prompt,
            'images'     => $imageDataUris,
            'provider'   => $model['provider'] ?? '',
            'model_data' => $model,
        ]);

        $imageType = 'img2img';

        if (!empty($result['images'])) {
            // Success — ONE image output
            $db->query(
                "INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, ?, 'success', ?)",
                [$internalId, $modelId, $prompt, $imageType, $referenceId]
            );

            // Send only the first image (single output from multi-image input)
            $this->sendEditImageToUser($chatId, $result['images'][0], $cost);
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, '✨ انجام شد.', $this->getMainMenuInlineKeyboard());
        } else {
            // Failure
            $errMsg = $result['error'] ?? 'خطای نامشخص';
            $db->query(
                "INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, ?, 'failed', ?)",
                [$internalId, $modelId, $prompt, $imageType, $referenceId]
            );
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, '⚠️ خطا: ' . $errMsg);
        }
    }

    /**
     * Send the generated image to user via multipart upload (data URI → temp file → sendPhotoFile).
     */
    private function sendEditImageToUser(int $chatId, string $urlOrData, int $cost): void
    {
        // data URI — most common from OpenRouter
        if (str_starts_with($urlOrData, 'data:')) {
            $parts = explode('base64,', $urlOrData, 2);
            $b64Data = $parts[1] ?? $parts[0] ?? '';
            $imageData = base64_decode($b64Data, true);
            if ($imageData && strlen($imageData) > 500) {
                $mime = 'image/png';
                if (str_contains($urlOrData, 'image/jpeg')) $mime = 'image/jpeg';
                elseif (str_contains($urlOrData, 'image/gif')) $mime = 'image/gif';
                elseif (str_contains($urlOrData, 'image/webp')) $mime = 'image/webp';
                $ext = str_replace('image/', '', $mime);
                $tmpFile = tempnam(sys_get_temp_dir(), 'edit_') . '.' . $ext;
                file_put_contents($tmpFile, $imageData);
                $this->baleClient->sendPhotoFile($chatId, $tmpFile, "✅ ویرایش تصویر انجام شد\n💎 هزینه: {$cost} اعتبار");
                @unlink($tmpFile);
                return;
            }
        }

        // HTTP URL — try direct, then download
        if (str_starts_with($urlOrData, 'http')) {
            $resp = $this->baleClient->sendPhoto($chatId, $urlOrData, "✅ ویرایش تصویر انجام شد\n💎 هزینه: {$cost} اعتبار");
            if (isset($resp['ok']) && $resp['ok'] === true) {
                return;
            }
            $ch = curl_init($urlOrData);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MobixBot/1.0)',
            ]);
            $imgData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode === 200 && strlen($imgData ?? '') > 500) {
                $mime = 'image/png';
                $first = substr($imgData, 0, 4);
                if (str_starts_with($first, "\xff\xd8")) $mime = 'image/jpeg';
                elseif (str_starts_with($first, "\x89PNG")) $mime = 'image/png';
                $ext = str_replace('image/', '', $mime);
                $tmpFile = tempnam(sys_get_temp_dir(), 'edit_') . '.' . $ext;
                file_put_contents($tmpFile, $imgData);
                $this->baleClient->sendPhotoFile($chatId, $tmpFile, "✅ ویرایش تصویر انجام شد\n💎 هزینه: {$cost} اعتبار");
                @unlink($tmpFile);
                return;
            }
        }

        Logger::error('sendEditImageToUser', 'Could not send image', ['type' => gettype($urlOrData)]);
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
        } catch (\Throwable $e) {
            return null;
        }
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
        } catch (\Throwable $e) {
        }
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
            'keyboard' => [[['text' => '/cancel'], ['text' => 'منو اصلی']]],
            'resize_keyboard' => true
        ];
    }
}