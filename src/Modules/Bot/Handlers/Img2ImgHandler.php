<?php

namespace Modules\Bot\Handlers;

use Modules\AI\AIService;
use Modules\Bot\CreditService;
use Database\Database;
use Database\Logger;
use Core\BotTextService;

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
            $chatId  = $update->getChatId();
            $userId  = $update->getUserId();
            $text    = $update->getText();
            $callbackData = $update->getCallbackData();
            $isCallback = $update->isCallback();

            // Membership check: block if not member of required channels
            if (!$this->checkMembership($userId, $chatId)) return;

            $state = $this->getUserState($userId);

            // AI processing lock
            if ($state === 'ai_processing') {
                if ($text === '/cancel') {
                    $this->baleClient->sendMessage($chatId, BotTextService::get('edit_ai_processing_warning'));
                    return;
                }
                $this->baleClient->sendMessage($chatId, BotTextService::get('edit_ai_processing_wait'));
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
                    $this->baleClient->sendMessage($chatId, BotTextService::get('edit_photo_prompt', ['max_photos' => $this->maxPhotos]), $this->getDoneKeyboard());
                }
                return;
            }

            // Prompt received
            if ($state === 'awaiting_edit_prompt') {
                $this->processEditRequest($chatId, $userId, $text);
                return;
            }

            $this->baleClient->sendMessage($chatId, BotTextService::get('edit_fallback_menu'), $this->getPersistentKeyboard());
        } catch (\Throwable $e) {
            error_log("Img2ImgHandler FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            Logger::error('Img2ImgHandler exception', ['user_id' => $userId ?? 0, 'error' => $e->getMessage()]);
            if (isset($chatId)) {
                $this->baleClient->sendMessage($chatId, BotTextService::get('edit_error', ['error' => $e->getMessage()]));
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
        $models = $db->query("SELECT id, name, display_name, cost_per_edit AS cost_per_image, description FROM ai_edit_models WHERE is_active = 1")->fetchAll();

        if (empty($models)) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('edit_no_active_models'));
            return;
        }

        $keyboard = ['inline_keyboard' => []];
        $msg = BotTextService::get('edit_model_selection_title');
        foreach ($models as $model) {
            $displayName = $model['display_name'] ?? $model['name'];
            $desc = $model['description'] ?? '';
            $msg .= '• ' . $displayName . ' — هزینه: ' . $model['cost_per_image'] . ' اعتبار';
            if ($desc) $msg .= ' — ' . $desc;
            $msg .= "\n";
            $keyboard['inline_keyboard'][] = [[
                'text' => $displayName,
                'callback_data' => "edit_select_model_{$model['id']}"
            ]];
        }

        $internalId = $this->resolveUserId($userId);
        $db->query("REPLACE INTO bot_state (user_id, state) VALUES (?, 'selecting_model_edit')", [$internalId]);
        $this->baleClient->sendMessage($chatId, $msg, $keyboard);
    }

    private function saveModelAndAskPhotos(int $chatId, int $userId, int $modelId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();
        $db->query(
            "UPDATE bot_state SET state = 'awaiting_edit_photo', extra_data = ? WHERE user_id = ?",
            [json_encode(['model_id' => $modelId, 'file_ids' => []]), $internalId]
        );
        $this->baleClient->sendMessage($chatId, BotTextService::get('edit_model_selected_photos', ['max_photos' => $this->maxPhotos]), $this->getDoneKeyboard());
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
                $this->baleClient->sendMessage($chatId, BotTextService::get('edit_max_photos', ['max_photos' => $this->maxPhotos]), $this->getDoneKeyboard());
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
                $this->baleClient->sendMessage($chatId, BotTextService::get('edit_photo_received'), $this->getDoneKeyboard());
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
            $this->baleClient->sendMessage($chatId, BotTextService::get('edit_min_photos'), $this->getDoneKeyboard());
            return;
        }

        $this->baleClient->sendMessage($chatId, BotTextService::get('edit_downloading'));

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
            $this->baleClient->sendMessage($chatId, BotTextService::get('edit_download_failed'));
            return;
        }

        $extra['photo_paths'] = $paths;
        unset($extra['file_ids']);

        $db->query(
            "UPDATE bot_state SET state = 'awaiting_edit_prompt', extra_data = ? WHERE user_id = ?",
            [json_encode($extra), $internalId]
        );

        $msg = BotTextService::get('edit_photos_received', ['count' => count($paths)]);
        if ($failedCount > 0) {
            $msg .= BotTextService::get('edit_photos_partial', ['failed' => $failedCount]);
        }
        $msg .= BotTextService::get('edit_enter_prompt');
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
            $this->baleClient->sendMessage($chatId, BotTextService::get('edit_state_error'));
            return;
        }

        $aiService = new AIService();
        $model = $aiService->getActiveModelById($modelId, 'image_editing');

        if (!$model) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, BotTextService::get('edit_model_not_found'));
            return;
        }

        $cost = (int) $model['cost_per_image'];

        // Credit check — single charge for one AI generation with all photos
        if (!CreditService::hasEnoughCredit($internalId, $cost)) {
            $this->clearUserState($internalId);
            $buyCreditKeyboard = [
                'inline_keyboard' => [
                    [['text' => "\xF0\x9F\x92\xB3 برای افزایش اعتبار کلیک کن", 'callback_data' => 'buy_credit']],
                ]
            ];
            $this->baleClient->sendMessage($chatId, BotTextService::get('edit_insufficient_credit', ['cost' => $cost]), $buyCreditKeyboard);
            return;
        }

        $referenceId = 'ai_edit_' . bin2hex(random_bytes(8));

        if (!CreditService::deduct($internalId, $cost, $referenceId)) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, BotTextService::get('edit_credit_deduct_error'));
            return;
        }

        $this->baleClient->sendMessage($chatId, BotTextService::get('edit_processing'));

        // Convert ALL photos to data URIs
        $imageDataUris = [];

        foreach ($paths as $photoPath) {
            if (!file_exists($photoPath)) {
                continue;
            }
            $photoData = file_get_contents($photoPath);
            @unlink($photoPath);

            if (empty($photoData) || strlen($photoData) < 500) {
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
                "INSERT INTO ai_requests (user_id, model_id, model_name, prompt, image_type, status, reference_id) VALUES (?, ?, ?, ?, 'img2img', 'failed', ?)",
                [$internalId, $modelId, $model['name'], $prompt, $referenceId]
            );
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, BotTextService::get('edit_invalid_images'));
            return;
        }

        // Single API call with ALL images in the `images` array
        $result = $aiService->generate([
            'model'      => $model['name'],
            'prompt'     => $prompt,
            'images'     => $imageDataUris,
            'provider'   => $model['provider'] ?? '',
            'model_data' => $model,
        ]);

        $imageType = 'img2img';

        // Check if model returned text instead of image
        if (!empty($result['text'])) {
            $textResponse = $result['text'];
            
            // Refund the image cost
            CreditService::creditBack($internalId, $cost, $referenceId . '_refund');
            
            // Calculate text-based cost
            $inputChars = mb_strlen($prompt);
            $outputChars = mb_strlen($textResponse);
            $costPerInputChar = (float)($model['cost_per_input_char'] ?? 0.000001);
            $costPerOutputChar = (float)($model['cost_per_output_char'] ?? 0.000002);
            $textCost = (int)ceil(($inputChars * $costPerInputChar) + ($outputChars * $costPerOutputChar));
            if ($textCost < 1) $textCost = 1;
            
            $textRefId = $referenceId . '_text';
            CreditService::deduct($internalId, $textCost, $textRefId);
            
            // Fetch actual USD cost
            $usage = $result['usage'] ?? [];
            $actualUsd = 0;
            if (!empty($usage)) {
                $actualUsd = (float)($usage['cost'] ?? 0);
                if ($actualUsd <= 0 && !empty($usage['cost_details']['upstream_inference_cost'] ?? null)) {
                    $actualUsd = (float)$usage['cost_details']['upstream_inference_cost'];
                }
            }
            
            \Core\AILogger::log('IMG2IMG_TEXT_FALLBACK', [
                'input_chars' => $inputChars,
                'output_chars' => $outputChars,
                'text_cost' => $textCost,
                'original_image_cost' => $cost,
                'actual_cost_usd' => $actualUsd,
                'model' => $model['name'],
            ]);
            
            $db->query(
                "INSERT INTO ai_requests (user_id, model_id, model_name, prompt, image_type, status, reference_id, actual_cost_usd, input_chars, output_chars, cost_charged) VALUES (?, ?, ?, ?, ?, 'success', ?, ?, ?, ?, ?)",
                [$internalId, $modelId, $model['name'], $prompt, $imageType, $referenceId, $actualUsd, $inputChars, $outputChars, $textCost]
            );
            
            $this->clearUserState($internalId);
            
            $displayText = mb_substr($textResponse, 0, 4000);
            $caption = BotTextService::get('edit_text_fallback_caption', ['text' => $displayText, 'cost' => $textCost]);
            $this->baleClient->sendMessage($chatId, $caption, $this->getMainMenuInlineKeyboard());
            return;
        }

        if (!empty($result['images'])) {
            // Success — ONE image output
            $usage = $result['usage'] ?? [];
            $actualUsd = 0;
            if (!empty($usage)) {
                $actualUsd = (float)($usage['cost'] ?? 0);
                if ($actualUsd <= 0 && !empty($usage['cost_details']['upstream_inference_cost'] ?? null)) {
                    $actualUsd = (float)$usage['cost_details']['upstream_inference_cost'];
                }
            }
            
            $db->query(
                "INSERT INTO ai_requests (user_id, model_id, model_name, prompt, image_type, status, reference_id, actual_cost_usd, cost_charged) VALUES (?, ?, ?, ?, ?, 'success', ?, ?, ?)",
                [$internalId, $modelId, $model['name'], $prompt, $imageType, $referenceId, $actualUsd, $cost]
            );

            // Send only the first image
            $this->sendEditImageToUser($chatId, $result['images'][0], $cost);
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, BotTextService::get('edit_complete'), $this->getMainMenuInlineKeyboard());
        } else {
            // Failure
            $errMsg = $result['error'] ?? 'خطای نامشخص';
            $db->query(
                "INSERT INTO ai_requests (user_id, model_id, model_name, prompt, image_type, status, reference_id) VALUES (?, ?, ?, ?, ?, 'failed', ?)",
                [$internalId, $modelId, $model['name'], $prompt, $imageType, $referenceId]
            );
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, BotTextService::get('edit_error', ['error' => $errMsg]));
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
                $this->baleClient->sendPhotoFile($chatId, $tmpFile, BotTextService::get('edit_image_caption', ['cost' => $cost]));
                @unlink($tmpFile);
                return;
            }
        }

        // HTTP URL — try direct, then download
        if (str_starts_with($urlOrData, 'http')) {
            $resp = $this->baleClient->sendPhoto($chatId, $urlOrData, BotTextService::get('edit_image_caption', ['cost' => $cost]));
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
                $this->baleClient->sendPhotoFile($chatId, $tmpFile, BotTextService::get('edit_image_caption', ['cost' => $cost]));
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
                [['text' => "\xE2\x9C\xA8 ساخت تصویر", 'callback_data' => 'generate_image'], ['text' => "\xF0\x9F\x96\xBC\xEF\xB8\x8F ویرایش عکس", 'callback_data' => 'edit_image']],
                [['text' => "\xF0\x9F\x92\xAC چت با هوش مصنوعی", 'callback_data' => 'start_chat'], ['text' => "\xF0\x9F\x91\xA4 حساب کاربری", 'callback_data' => 'account']],
                [['text' => "\xF0\x9F\x92\xB3 خرید اعتبار", 'callback_data' => 'buy_credit'], ['text' => "\xE2\x9D\x93 راهنما", 'callback_data' => 'help']],
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