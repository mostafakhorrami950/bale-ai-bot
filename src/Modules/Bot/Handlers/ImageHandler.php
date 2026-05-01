<?php

namespace Modules\Bot\Handlers;

use Modules\AI\AIService;
use Modules\Bot\CreditService;
use Modules\Bot\Models\User;
use Database\Database;
use Database\Logger;

class ImageHandler extends BaseHandler
{
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

            // Block user if AI is processing
            if ($state === 'ai_processing') {
                if ($text === '/cancel') {
                    $this->baleClient->sendMessage($chatId, "⚠️ درخواست شما در حال پردازش توسط هوش مصنوعی است. در صورت لغو، هزینه عودت داده نمی‌شود و خروجی پس از آماده شدن ارسال خواهد شد.");
                    return;
                }
                $this->baleClient->sendMessage($chatId, "⏳ لطفاً صبور باشید، درخواست قبلی شما در حال پردازش است...");
                return;
            }

            // Entry point 1: Button click or command
            if ($callbackData === 'generate_image' || $text === '🎨 ساخت تصویر') {
                $this->showModelSelection($chatId, $userId, 'image');
                return;
            }

            // Step 2: Handle Model Selection
            if ($isCallback && is_string($callbackData) && str_starts_with($callbackData, 'img_select_model_')) {
                $modelId = (int) str_replace('img_select_model_', '', $callbackData);
                $this->saveSelectedModelAndAskPrompt($chatId, $userId, $modelId);
                return;
            }

            // Step 3: Handle Prompt Input
            if ($state === 'awaiting_image_prompt') {
                if ($text === '/cancel' || $text === 'منو اصلی') return;
                $this->processPrompt($chatId, $userId, $text);
                return;
            }

            // Fallback
            $this->baleClient->sendMessage($chatId, "🤖 لطفاً از منوی زیر یکی از گزینه‌ها را انتخاب کنید:");
        } catch (\Throwable $e) {
            error_log("ImageHandler FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            Logger::error('ImageHandler exception', ['user_id' => $update->getUserId(), 'error' => $e->getMessage()]);
            $this->baleClient->sendMessage($update->getChatId(), "⚠️ متأسفانه مشکلی پیش آمد. لطفاً دوباره تلاش کنید.");
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

    private function showModelSelection(int $chatId, int $userId, string $type): void
    {
        try {
            $db = Database::getInstance();
            $models = $db->query("SELECT id, name, display_name, cost_per_image, description FROM ai_image_models WHERE is_active = 1")->fetchAll();
            if (empty($models)) {
                $this->baleClient->sendMessage($chatId, "❌ در حال حاضر هیچ مدل فعالی یافت نشد.");
                return;
            }
            $keyboard = ['inline_keyboard' => []];
            $msg = "🎯 لطفاً مدل هوش مصنوعی مورد نظر خود را انتخاب کنید:\n\n";
            foreach ($models as $model) {
                $displayName = $model['display_name'] ?? $model['name'];
                $desc = $model['description'] ?? '';
                $msg .= "• {$displayName} — هزینه: {$model['cost_per_image']} اعتبار";
                if ($desc) $msg .= " — {$desc}";
                $msg .= "\n";
                $keyboard['inline_keyboard'][] = [[
                    'text' => $displayName,
                    'callback_data' => "img_select_model_{$model['id']}"
                ]];
            }
            $internalId = $this->resolveUserId($userId);
            $nextState = ($type === 'image') ? 'selecting_model_image' : 'selecting_model_edit';
            $db->query(
                "INSERT INTO bot_state (user_id, state, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE state = ?, updated_at = NOW()",
                [$internalId, $nextState, $nextState]
            );
            $this->baleClient->sendMessage($chatId, $msg, $keyboard);
        } catch (\Throwable $e) {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در دریافت لیست مدل‌ها.");
        }
    }

    private function saveSelectedModelAndAskPrompt(int $chatId, int $userId, int $modelId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();
        $model = $db->query("SELECT * FROM ai_image_models WHERE id = ?", [$modelId])->fetch();
        if (!$model) {
            $model = $db->query("SELECT * FROM ai_edit_models WHERE id = ?", [$modelId])->fetch();
        }
        if (!$model) {
            $this->baleClient->sendMessage($chatId, "⚠️ مدل انتخاب شده معتبر نیست.");
            return;
        }
        $db->query(
            "UPDATE bot_state SET state = 'awaiting_image_prompt', extra_data = ? WHERE user_id = ?",
            [json_encode(['model_id' => $modelId]), $internalId]
        );
        $this->baleClient->sendMessage($chatId, "🎨 مدل «{$model['name']}» انتخاب شد.\n\nلطفاً متن تصویر مورد نظر خود را بنویسید:");
    }

    private function processPrompt(int $chatId, int $userId, string $prompt): void
    {
        if (empty(trim($prompt))) {
            $this->baleClient->sendMessage($chatId, "⚠️ لطفاً یک متن معتبر وارد کنید.");
            return;
        }

        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();

        $db->query("UPDATE bot_state SET state = 'ai_processing' WHERE user_id = ?", [$internalId]);

        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $modelId = $extra['model_id'] ?? null;

        $aiService = new AIService();
        $model = $aiService->getActiveModelById((int)$modelId, 'image_generation');

        if (!$model) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "❌ مدل یافت نشد. لطفاً دوباره انتخاب کنید.");
            return;
        }

        $cost = (int) $model['cost_per_image'];
        if (!CreditService::hasEnoughCredit($internalId, $cost)) {
            $this->clearUserState($internalId);
            $buyCreditKeyboard = [
                'inline_keyboard' => [
                    [['text' => "\xF0\x9F\x92\xB3 برای افزایش اعتبار کلیک کن", 'callback_data' => 'buy_credit']],
                ]
            ];
            $this->baleClient->sendMessage($chatId, "❌ اعتبار شما کافی نیست (نیاز به {$cost} اعتبار).", $buyCreditKeyboard);
            return;
        }

        $referenceId = 'ai_' . bin2hex(random_bytes(8));

        if (!CreditService::deduct($internalId, $cost, $referenceId)) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در کسر اعتبار. لطفاً با پشتیبانی تماس بگیرید.");
            return;
        }

        $this->baleClient->sendMessage($chatId, "⏳ در حال ساخت تصویر توسط «{$model['name']}»... لطفاً چند لحظه صبر کنید.");

        \Core\AILogger::log('IMAGE_GENERATE_START', [
            'user_id' => $internalId,
            'model' => $model['name'],
            'provider' => $model['provider'] ?? '',
            'cost' => $cost,
            'prompt_len' => mb_strlen($prompt),
        ]);

        // Pass full model_data for MetisAI config support
        $result = $aiService->generate([
            'model'      => $model['name'],
            'prompt'     => $prompt,
            'provider'   => $model['provider'] ?? '',
            'model_data' => $model,
        ]);

        if (isset($result['error'])) {
            \Core\AILogger::imageResult($internalId, 'text2img', $model['name'], $cost, false, $result['error']);
            $db->query("INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, 'text2img', 'failed', ?)", [$internalId, $modelId, $prompt, $referenceId]);
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در تولید تصویر: " . $result['error']);
            return;
        }

        $db->query("INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, 'text2img', 'success', ?)", [$internalId, $modelId, $prompt, $referenceId]);

        $images = $result['images'] ?? [];
        \Core\AILogger::imageResult($internalId, 'text2img', $model['name'], $cost, true);
        \Core\AILogger::log('IMAGE_SEND', ['count' => count($images)]);
        foreach ($images as $urlOrData) {
            $sent = $this->sendImageToUser($chatId, $urlOrData, $cost);
            if (!$sent) {
                \Core\AILogger::error('image_send', 'Failed to send image to user', ['chat_id' => $chatId]);
            }
        }

        $this->clearUserState($internalId);
        $this->baleClient->sendMessage($chatId, "✨ عملیات با موفقیت پایان یافت.", $this->getMainMenuInlineKeyboard());
    }

    private function getUserState(int $baleUserId): ?string
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT bs.state FROM bot_state bs JOIN users u ON bs.user_id = u.id WHERE u.bale_user_id = ?", [$baleUserId]);
            $row = $stmt->fetch();
            return $row['state'] ?? null;
        } catch (\Throwable $e) { return null; }
    }

    private function clearUserState(int $internalId): void
    {
        try { $db = Database::getInstance(); $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]); } catch (\Throwable $e) {}
    }

    /**
     * Send an image to the user, handling data URIs, HTTP URLs, and temp files.
     * Returns true on success, false on failure.
     */
    private function sendImageToUser(int $chatId, string $urlOrData, int $cost): bool
    {
        // Case 1: data URI → convert to temp file, send as multipart
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
                $tmpFile = tempnam(sys_get_temp_dir(), 'img_') . '.' . $ext;
                file_put_contents($tmpFile, $imageData);
                $sent = $this->baleClient->sendPhotoFile($chatId, $tmpFile, "✅ خروجی هوش مصنوعی\n💎 هزینه کسر شده: {$cost} اعتبار");
                @unlink($tmpFile);
                return $sent;
            }
            return false;
        }

        // Case 2: HTTP URL → try direct send first, if fails download and send multipart
        if (str_starts_with($urlOrData, 'http')) {
            // Try direct URL send first (Bale downloads it)
            $resp = $this->baleClient->sendPhoto($chatId, $urlOrData, "✅ خروجی هوش مصنوعی\n💎 هزینه کسر شده: {$cost} اعتبار");
            if (isset($resp['ok']) && $resp['ok'] === true) {
                return true;
            }

            // Bale can't download it — download ourselves and send as multipart
            \Core\AILogger::log('IMAGE_DOWNLOAD_RETRY', ['url' => substr($urlOrData, 0, 100)]);
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
                elseif (str_starts_with($first, "GIF8")) $mime = 'image/gif';
                $ext = str_replace('image/', '', $mime);
                $tmpFile = tempnam(sys_get_temp_dir(), 'img_') . '.' . $ext;
                file_put_contents($tmpFile, $imgData);
                $sent = $this->baleClient->sendPhotoFile($chatId, $tmpFile, "✅ خروجی هوش مصنوعی\n💎 هزینه کسر شده: {$cost} اعتبار");
                @unlink($tmpFile);
                return $sent;
            }

            \Core\AILogger::error('image_download_failed', 'Could not download image', ['http' => $httpCode, 'url' => substr($urlOrData, 0, 100)]);
            return false;
        }

        // Case 3: local file path → send as multipart
        if (file_exists($urlOrData)) {
            $sent = $this->baleClient->sendPhotoFile($chatId, $urlOrData, "✅ خروجی هوش مصنوعی\n💎 هزینه کسر شده: {$cost} اعتبار");
            @unlink($urlOrData);
            return $sent;
        }

        \Core\AILogger::error('image_send_unknown_type', 'Unknown image type', ['type' => gettype($urlOrData), 'val' => substr((string)$urlOrData, 0, 50)]);
        return false;
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
}