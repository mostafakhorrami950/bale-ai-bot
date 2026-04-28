<?php

namespace Modules\Bot\Handlers;

use Modules\AI\AIService;
use Modules\Bot\CreditService;
use Modules\Bot\Models\User;
use Database\Database;
use Database\Logger;

class Img2ImgHandler extends BaseHandler
{
    public function handle($update): void
    {
        try {
            $chatId = $update->getChatId();
            $userId = $update->getUserId();
            $text = $update->getText();
            $callbackData = $update->getCallbackData();

            $state = $this->getUserState($userId);

            // Priority 1: AI processing lock
            if ($state === 'ai_processing') {
                if ($text === '/cancel') {
                    $this->baleClient->sendMessage($chatId, "⚠️ درخواست شما در حال پردازش است. در صورت لغو، هزینه عودت داده نمی‌شود و خروجی پس از آماده شدن ارسال خواهد شد.");
                    return;
                }
                $this->baleClient->sendMessage($chatId, "⏳ لطفاً صبور باشید، هوش مصنوعی در حال ویرایش عکس شماست...");
                return;
            }

            // Entry Point
            if ($callbackData === 'edit_image' || $text === '🖼 ویرایش عکس') {
                $this->showModelSelection($chatId, $userId);
                return;
            }

            // Step 2: Handle Model Selection
            if ($update->isCallback() && str_starts_with($callbackData, 'select_model_')) {
                $modelId = (int) str_replace('select_model_', '', $callbackData);
                $this->saveModelAndAskPhotos($chatId, $userId, $modelId);
                return;
            }

            // Step 3: Handle Photo Upload(s) - 1 to 5 photos
            if ($state === 'awaiting_edit_photo') {
                if ($update->hasPhoto()) {
                    $this->storePhotoAndCheckDone($chatId, $userId, $update->getPhotoFileId());
                } else {
                    $this->baleClient->sendMessage($chatId, "📸 لطفاً عکس(های) مورد نظر برای ویرایش را ارسال کنید.\n(حداقل ۱ و حداکثر ۵ عکس)\nپس از اتمام، دکمه «✅ انجام شد» را بزنید.", $this->getPhotoDoneKeyboard());
                }
                return;
            }

            // Step 3b: User clicked "✅ انجام شد" after uploading photos
            if ($callbackData === 'edit_photos_done') {
                $this->finalizePhotosAndAskPrompt($chatId, $userId);
                return;
            }

            // Step 4: Handle Prompt
            if ($state === 'awaiting_edit_prompt') {
                $this->processEditRequest($chatId, $userId, $text);
                return;
            }

            $this->baleClient->sendMessage($chatId, "🤖 لطفاً یکی از گزینه‌های منو را انتخاب کنید:", $this->getPersistentKeyboard());
        } catch (\Throwable $e) {
            error_log("Img2ImgHandler FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            Logger::error('Img2ImgHandler exception', ['user_id' => $userId, 'error' => $e->getMessage()]);
            $this->baleClient->sendMessage($chatId, "⚠️ خطایی رخ داد. مجدداً تلاش کنید.");
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
        $this->baleClient->sendMessage($chatId, "🎯 ابتدا مدل مورد نظر برای ویرایش را انتخاب کنید:", $keyboard);
    }

    private function saveModelAndAskPhotos(int $chatId, int $userId, int $modelId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();
        $db->query(
            "UPDATE bot_state SET state = 'awaiting_edit_photo', extra_data = ? WHERE user_id = ?",
            [json_encode(['model_id' => $modelId, 'photos' => []]), $internalId]
        );
        $this->baleClient->sendMessage($chatId, "📸 مدل انتخاب شد. حالا عکس‌های مورد نظر را بفرستید (حداقل ۱ و حداکثر ۵).\nپس از اتمام، دکمه «✅ انجام شد» را بزنید.", $this->getPhotoDoneKeyboard());
    }

    private function getPhotoDoneKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '✅ انجام شد', 'callback_data' => 'edit_photos_done']]
            ]
        ];
    }

    private function storePhotoAndCheckDone(int $chatId, int $userId, string $fileId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();
        
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        
        $photos = $extra['photos'] ?? [];
        
        // Check max limit
        if (count($photos) >= 5) {
            $this->baleClient->sendMessage($chatId, "⚠️ حداکثر ۵ عکس مجاز است. لطفاً دکمه «✅ انجام شد» را بزنید.", $this->getPhotoDoneKeyboard());
            return;
        }
        
        // Download and store the photo
        $fileInfo = $this->baleClient->getFile($fileId);
        $imageData = @file_get_contents($fileInfo['file_path'] ?? '');
        
        if (!$imageData) {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در دریافت فایل از بله.");
            return;
        }
        
        $photos[] = base64_encode($imageData);
        $extra['photos'] = $photos;
        
        $db->query(
            "UPDATE bot_state SET extra_data = ? WHERE user_id = ?",
            [json_encode($extra), $internalId]
        );
        
        $remaining = 5 - count($photos);
        $this->baleClient->sendMessage($chatId, "✅ عکس شماره " . count($photos) . " دریافت شد.\n" . ($remaining > 0 ? "می‌توانید تا {$remaining} عکس دیگر ارسال کنید." : "حداکثر تعداد عکس دریافت شد.") . "\nپس از اتمام، دکمه «✅ انجام شد» را بزنید.", $this->getPhotoDoneKeyboard());
    }

    private function finalizePhotosAndAskPrompt(int $chatId, int $userId): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();
        
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $photos = $extra['photos'] ?? [];
        
        if (count($photos) < 1) {
            $this->baleClient->sendMessage($chatId, "⚠️ حداقل ۱ عکس باید ارسال کنید. لطفاً عکس(های) خود را بفرستید.", $this->getPhotoDoneKeyboard());
            return;
        }
        
        $db->query(
            "UPDATE bot_state SET state = 'awaiting_edit_prompt' WHERE user_id = ?",
            [$internalId]
        );
        
        $this->baleClient->sendMessage($chatId, "✏️ " . count($photos) . " عکس دریافت شد. حالا متن تغییرات (Prompt) را بنویسید:");
    }

    private function processEditRequest(int $chatId, int $userId, string $prompt): void
    {
        $internalId = $this->resolveUserId($userId);
        $db = Database::getInstance();
        
        $db->query("UPDATE bot_state SET state = 'ai_processing' WHERE user_id = ?", [$internalId]);

        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        
        $aiService = new AIService();
        $model = $aiService->getActiveModelById((int)($extra['model_id'] ?? 0));

        if (!$model) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "❌ خطا در بازیابی اطلاعات. دوباره شروع کنید.");
            return;
        }

        $cost = (int) $model['cost_per_image'];
        $photos = $extra['photos'] ?? [];
        
        if (empty($photos)) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "❌ عکسی یافت نشد. دوباره شروع کنید.");
            return;
        }

        if (!CreditService::hasEnoughCredit($internalId, $cost)) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "❌ اعتبار کافی ندارید.");
            return;
        }

        // Generate unique reference for this request
        $referenceId = 'ai_edit_' . bin2hex(random_bytes(8));

        // Deduct credits IMMEDIATELY before API call (issue #3 fix)
        if (!CreditService::deduct($internalId, $cost, $referenceId)) {
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در کسر اعتبار. لطفاً با پشتیبانی تماس بگیرید.");
            return;
        }

        $this->baleClient->sendMessage($chatId, "⏳ در حال پردازش ویرایش عکس... لطفاً صبور باشید.");

        // Process all photos
        $allImages = [];
        $hasError = false;
        $errorMsg = '';

        foreach ($photos as $index => $photoBase64) {
            $result = $aiService->generate([
                'model'  => $model['name'],
                'prompt' => $prompt,
                'image'  => $photoBase64
            ]);

            if (isset($result['error'])) {
                $hasError = true;
                $errorMsg = $result['error'];
                Logger::error('Img2ImgHandler: AI edit failed', [
                    'user_id'  => $internalId,
                    'photo_idx' => $index,
                    'error'    => $result['error'],
                ]);
                break;
            }

            if (!empty($result['images'])) {
                $allImages = array_merge($allImages, $result['images']);
            }
        }

        $imageType = 'img2img';
        $totalImages = count($allImages);

        if ($hasError && empty($allImages)) {
            // All failed - log as failed
            $db->query(
                "INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, ?, 'failed', ?)",
                [$internalId, $extra['model_id'], $prompt, $imageType, $referenceId]
            );
            $this->clearUserState($internalId);
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در ویرایش تصاویر: " . $errorMsg);
            return;
        }

        // Log successful request(s)
        $db->query(
            "INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, ?, 'success', ?)",
            [$internalId, $extra['model_id'], $prompt, $imageType, $referenceId]
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
