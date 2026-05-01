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
            $models = $db->query("SELECT id, name, cost_per_image FROM ai_image_models WHERE is_active = 1")->fetchAll();
            if (empty($models)) {
                $this->baleClient->sendMessage($chatId, "❌ در حال حاضر هیچ مدل فعالی یافت نشد.");
                return;
            }
            $keyboard = ['inline_keyboard' => []];
            foreach ($models as $model) {
            $keyboard['inline_keyboard'][] = [[
                'text' => "🤖 {$model['name']} (هزینه: {$model['cost_per_image']} اعتبار)",
                'callback_data' => "img_select_model_{$model['id']}"
            ]];
            }
            $internalId = $this->resolveUserId($userId);
            $nextState = ($type === 'image') ? 'selecting_model_image' : 'selecting_model_edit';
            $db->query(
                "INSERT INTO bot_state (user_id, state, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE state = ?, updated_at = NOW()",
                [$internalId, $nextState, $nextState]
            );
            $this->baleClient->sendMessage($chatId, "🎯 لطفاً مدل هوش مصنوعی مورد نظر خود را انتخاب کنید:", $keyboard);
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
        $model = $aiService->getActiveModelById((int)$modelId);

        if (!$model) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "❌ مدل یافت نشد. لطفاً دوباره انتخاب کنید.");
            return;
        }

        $cost = (int) $model['cost_per_image'];
        if (!CreditService::hasEnoughCredit($internalId, $cost)) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "❌ اعتبار شما کافی نیست (نیاز به {$cost} اعتبار).");
            return;
        }

        $referenceId = 'ai_' . bin2hex(random_bytes(8));

        if (!CreditService::deduct($internalId, $cost, $referenceId)) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در کسر اعتبار. لطفاً با پشتیبانی تماس بگیرید.");
            return;
        }

        $this->baleClient->sendMessage($chatId, "⏳ در حال ساخت تصویر توسط «{$model['name']}»... لطفاً چند لحظه صبر کنید.");

        // Pass full model_data for MetisAI config support
        $result = $aiService->generate([
            'model'      => $model['name'],
            'prompt'     => $prompt,
            'provider'   => $model['provider'] ?? '',
            'model_data' => $model,
        ]);

        if (isset($result['error'])) {
            $db->query("INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, 'text2img', 'failed', ?)", [$internalId, $modelId, $prompt, $referenceId]);
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در تولید تصویر: " . $result['error']);
            return;
        }

        $db->query("INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, 'text2img', 'success', ?)", [$internalId, $modelId, $prompt, $referenceId]);

        $images = $result['images'] ?? [];
        foreach ($images as $urlOrData) {
            // Download remote images (OpenRouter URLs) or use data URIs directly
            $photoToSend = $urlOrData;
            if (str_starts_with($urlOrData, 'http')) {
                // Download remote URL and send as file data
                $ch = curl_init($urlOrData);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $imageData = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200 && strlen($imageData ?? '') > 500) {
                    // Check if it's SVG or non-standard image
                    $finfo = finfo_open(FINFO_MIME_TYPE);
                    $mime = $finfo ? finfo_buffer($finfo, $imageData) : 'image/png';
                    finfo_close($finfo);
                    $b64 = base64_encode($imageData);
                    $photoToSend = 'data:' . $mime . ';base64,' . $b64;
                } else {
                    // Fallback: try sending the URL directly
                    error_log("ImageHandler: download failed for URL, http=$httpCode");
                }
            }
            $this->baleClient->sendPhoto($chatId, $photoToSend, "✅ خروجی هوش مصنوعی\n💎 هزینه کسر شده: {$cost} اعتبار");
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

    private function getMainMenuInlineKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '🎨 ساخت تصویر', 'callback_data' => 'generate_image'], ['text' => '🖼 ویرایش عکس', 'callback_data' => 'edit_image']],
                [['text' => '👤 حساب من', 'callback_data' => 'account'], ['text' => '💳 شارژ اعتبار', 'callback_data' => 'buy_credit']]
            ]
        ];
    }
}