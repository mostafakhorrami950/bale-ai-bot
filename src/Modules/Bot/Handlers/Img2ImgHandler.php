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
            $isCallback = $update->isCallback();

            if ($isCallback) {
                $this->askForPhoto($chatId, $userId);
                return;
            }

            if ($text === '🖼 ویرایش عکس') {
                $this->askForPhoto($chatId, $userId);
                return;
            }

            $state = $this->getUserState($userId);

            if ($state === 'awaiting_edit_photo') {
                if ($update->hasPhoto()) {
                    $fileId = $update->getPhotoFileId();
                    $this->storePhotoAndAskPrompt($chatId, $userId, $fileId);
                } else {
                    $this->baleClient->sendMessage($chatId, "📸 لطفاً یک عکس ارسال کنید.");
                }
                return;
            }

            if ($state === 'awaiting_edit_prompt') {
                $photoData = $this->getStoredPhotoData($userId);
                if ($photoData) {
                    $this->processEdit($chatId, $userId, $text, $photoData);
                } else {
                    $this->baleClient->sendMessage($chatId, "⚠️ عکس ذخیره شده یافت نشد. لطفاً دوباره از اول شروع کنید.");
                    $this->clearUserStateById($userId);
                }
                return;
            }

            $this->baleClient->sendMessage($chatId, "🤖 لطفاً از منوی زیر گزینه‌ای را انتخاب کنید:", $this->getMainMenuKeyboard());
        } catch (\Throwable $e) {
            Logger::error('Img2ImgHandler exception', [
                'user_id' => $update->getUserId(),
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
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
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function askForPhoto(int $chatId, int $userId): void
    {
        $internalId = $this->resolveUserId($userId);
        if ($internalId) {
            Database::getInstance()->query(
                "INSERT INTO bot_state (user_id, state, updated_at) VALUES (?, 'awaiting_edit_photo', NOW())
                 ON DUPLICATE KEY UPDATE state='awaiting_edit_photo', updated_at=NOW()",
                [$internalId]
            );
        }
        $this->baleClient->sendMessage($chatId, "🖼 لطفاً عکسی که می‌خواهید ویرایش کنید را ارسال نمایید:");
    }

    private function storePhotoAndAskPrompt(int $chatId, int $userId, string $fileId): void
    {
        try {
            $photoBase64 = $this->downloadPhotoAsBase64($fileId);
            if (!$photoBase64) {
                $this->baleClient->sendMessage($chatId, "⚠️ دریافت عکس از سرور با مشکل مواجه شد. لطفاً دوباره تلاش کنید.");
                return;
            }

            $internalId = $this->resolveUserId($userId);
            if ($internalId) {
                Database::getInstance()->query(
                    "INSERT INTO bot_state (user_id, state, photo_base64, extra_data, updated_at)
                     VALUES (?, 'awaiting_edit_prompt', ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE state='awaiting_edit_prompt', photo_base64=?, extra_data=?, updated_at=NOW()",
                    [$internalId, $photoBase64, '{}', $photoBase64, '{}']
                );
            }

            $this->baleClient->sendMessage($chatId, "✏️ عکس دریافت شد. حالا لطفاً متن مورد نظر برای ویرایش را بنویسید:");
        } catch (\Throwable $e) {
            Logger::error('Img2ImgHandler: storePhotoData failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            $this->baleClient->sendMessage($chatId, "⚠️ خطایی در ذخیره عکس رخ داد. لطفاً دوباره تلاش کنید.");
        }
    }

    private function processEdit(int $chatId, int $userId, string $prompt, string $photoBase64): void
    {
        if (empty(trim($prompt))) {
            $this->baleClient->sendMessage($chatId, "⚠️ لطفاً یک متن معتبر وارد کنید.");
            return;
        }

        $user = User::findByBaleId($userId);
        if (!$user) {
            $this->baleClient->sendMessage($chatId, "⚠️ کاربر یافت نشد.");
            return;
        }
        $internalId = (int) $user['id'];

        $this->clearUserStateById($internalId);

        $aiService = new AIService();
        $model = $aiService->getFirstActiveModel();
        if (!$model) {
            Logger::error('Img2ImgHandler: no active AI model found');
            $this->baleClient->sendMessage($chatId, "❌ مدل فعالی یافت نشد، لطفاً بعداً تلاش کنید.");
            return;
        }

        $cost = (int) $model['cost_per_image'];

        if (!CreditService::hasEnoughCredit($internalId, $cost)) {
            $this->baleClient->sendMessage($chatId, "❌ اعتبار شما کافی نیست.\n💰 هزینه هر ویرایش: {$cost} اعتبار");
            return;
        }

        $referenceId = 'ai_img_' . $internalId . '_' . time() . '_' . bin2hex(random_bytes(4));

        $this->baleClient->sendMessage($chatId, "⏳ در حال ویرایش تصویر... لطفاً چند لحظه صبر کنید.");

        $result = $aiService->generate([
            'model'  => $model['name'],
            'prompt' => $prompt,
            'image'  => $photoBase64,
        ]);

        if (isset($result['error'])) {
            Logger::error('Img2ImgHandler: AI edit failed', [
                'user_id' => $userId,
                'model'   => $model['name'],
                'error'   => $result['error'],
            ]);
            $this->baleClient->sendMessage($chatId, "⚠️ متأسفانه مشکلی در ویرایش تصویر پیش آمد. لطفاً دوباره تلاش کنید.");
            $this->logAiRequest($internalId, (int) $model['id'], $prompt, 'img2img', 'failed', $referenceId);
            return;
        }

        $images = $result['images'];
        $caption = "✅ ویرایش شد با مدل {$model['name']}\n💰 هزینه: {$cost} اعتبار";

        $allSent = true;
        foreach ($images as $url) {
            $response = $this->baleClient->sendPhoto($chatId, $url, $caption);
            if (!isset($response['ok']) || $response['ok'] !== true) {
                $allSent = false;
                Logger::error('Img2ImgHandler: sendPhoto failed', [
                    'user_id' => $userId,
                    'url'     => $url,
                ]);
            }
            $caption = null;
        }

        if ($allSent) {
            $deducted = CreditService::deduct($internalId, $cost, $referenceId);
            if (!$deducted) {
                Logger::error('Img2ImgHandler: credit deduction failed', [
                    'user_id'      => $internalId,
                    'amount'       => $cost,
                    'reference_id' => $referenceId,
                ]);
            }
        }

        $this->logAiRequest($internalId, (int) $model['id'], $prompt, 'img2img', 'success', $referenceId);

        $this->clearUserStateById($internalId);
        $this->baleClient->sendMessage($chatId, "✅ تصویر با موفقیت ویرایش شد!", $this->getMainMenuKeyboard());
    }

    private function downloadPhotoAsBase64(string $fileId): ?string
    {
        try {
            $fileInfo = $this->baleClient->getFile($fileId);
            if (!$fileInfo || !isset($fileInfo['file_path'])) {
                Logger::error('Img2ImgHandler: getFile failed', ['user_id' => 0, 'file_id' => $fileId]);
                return null;
            }
            $fileUrl = $fileInfo['file_path'];
            $imageData = @file_get_contents($fileUrl);
            if ($imageData === false) return null;
            return base64_encode($imageData);
        } catch (\Throwable $e) {
            Logger::error('Img2ImgHandler: downloadPhoto failed', ['error' => $e->getMessage()]);
            return null;
        }
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

    private function getStoredPhotoData(int $baleUserId): ?string
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query(
                "SELECT bs.photo_base64 FROM bot_state bs 
                 JOIN users u ON bs.user_id = u.id 
                 WHERE u.bale_user_id = ? AND bs.state = 'awaiting_edit_prompt'",
                [$baleUserId]
            );
            $row = $stmt->fetch();
            return $row['photo_base64'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function clearUserStateById(int $internalId): void
    {
        try {
            $db = Database::getInstance();
            $db->query("UPDATE bot_state SET photo_base64 = NULL, state = 'idle' WHERE user_id = ?", [$internalId]);
        } catch (\Throwable $e) {
            // Silent
        }
    }

    private function getMainMenuKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => "🎨 ساخت تصویر"], ['text' => "🖼 ویرایش عکس"]],
                [['text' => "👤 حساب من"], ['text' => "💳 شارژ اعتبار"]],
                [['text' => "❓ راهنما"]]
            ],
            'resize_keyboard' => true
        ];
    }

    private function logAiRequest(int $userId, int $modelId, string $prompt, string $imageType, string $status, string $referenceId): void
    {
        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, ?, ?, ?)",
                [$userId, $modelId, $prompt, $imageType, $status, $referenceId]
            );
        } catch (\Throwable $e) {
            Logger::error('Img2ImgHandler: logAiRequest failed', ['error' => $e->getMessage()]);
        }
    }
}