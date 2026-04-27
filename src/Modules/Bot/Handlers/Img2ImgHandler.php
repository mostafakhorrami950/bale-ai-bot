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
            $chatId  = $update->getChatId();
            $userId  = $update->getUserId();
            $text    = $update->getText();
            $isCallback = $update->isCallback();

            if ($isCallback) {
                $this->askForPhoto($chatId, $userId);
                return;
            }

            if ($text === '🖼️ ویرایش عکس') {
                $this->askForPhoto($chatId, $userId);
                return;
            }

            $state = $this->getUserState($userId);

            if ($state === 'awaiting_edit_photo') {
                $this->processPhoto($chatId, $userId, $update);
                return;
            }

            if ($state === 'awaiting_edit_prompt') {
                $this->processPrompt($chatId, $userId, $text);
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

    private function askForPhoto(int $chatId, int $userId): void
    {
        $this->setUserState($userId, 'awaiting_edit_photo');
        $this->baleClient->sendMessage($chatId, "🖼️ لطفاً عکسی که می‌خواهید ویرایش کنید را ارسال نمایید:");
    }

    private function processPhoto(int $chatId, int $userId, $update): void
    {
        if (!$update->hasPhoto()) {
            $this->baleClient->sendMessage($chatId, "⚠️ لطفاً یک عکس ارسال کنید.");
            return;
        }

        $fileId = $update->getPhotoFileId();
        if (!$fileId) {
            $this->baleClient->sendMessage($chatId, "⚠️ دریافت عکس با مشکل مواجه شد. لطفاً دوباره تلاش کنید.");
            return;
        }

        $fileInfo = $this->baleClient->getFile($fileId);
        if (!$fileInfo || !isset($fileInfo['file_path'])) {
            Logger::error('Img2ImgHandler: getFile failed', ['user_id' => $userId, 'file_id' => $fileId]);
            $this->baleClient->sendMessage($chatId, "⚠️ دریافت عکس از سرور با مشکل مواجه شد. لطفاً دوباره تلاش کنید.");
            return;
        }

        $fileContent = $this->baleClient->downloadFile($fileInfo['file_path']);
        if ($fileContent === null) {
            $this->baleClient->sendMessage($chatId, "⚠️ دانلود عکس با مشکل مواجه شد. لطفاً دوباره تلاش کنید.");
            return;
        }

        $tempDir = sys_get_temp_dir() . '/bale_ai_edits';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempFile = $tempDir . '/' . $userId . '_' . time() . '.jpg';
        file_put_contents($tempFile, $fileContent);

        $base64 = base64_encode($fileContent);

        $this->setUserState($userId, 'awaiting_edit_prompt');
        $this->storePhotoData($userId, $base64, $tempFile);

        $this->baleClient->sendMessage($chatId, "✅ عکس دریافت شد.\n📝 لطفاً متن توضیح ویرایش مورد نظر خود را بنویسید:");
    }

    private function processPrompt(int $chatId, int $userId, string $prompt): void
    {
        if (empty(trim($prompt))) {
            $this->baleClient->sendMessage($chatId, "⚠️ لطفاً یک متن معتبر وارد کنید.");
            return;
        }

        $photoData = $this->getStoredPhotoData($userId);
        $this->clearUserState($userId);
        $this->clearPhotoData($userId);

        if (!$photoData || !isset($photoData['base64'])) {
            $this->baleClient->sendMessage($chatId, "⚠️ عکس ذخیره‌شده یافت نشد. لطفاً دوباره از اول شروع کنید.");
            return;
        }

        $imageBase64 = $photoData['base64'];
        $tempFile = $photoData['temp_file'] ?? null;

        $user = User::findByBaleId($userId);
        if (!$user) {
            $this->baleClient->sendMessage($chatId, "⚠️ کاربر یافت نشد. لطفاً ابتدا با /start ثبت‌نام کنید.");
            return;
        }
        $internalId = (int) $user['id'];

        $aiService = new AIService();
        $model = $aiService->getFirstActiveModel();
        if (!$model) {
            Logger::error('Img2ImgHandler: no active AI model found');
            $this->baleClient->sendMessage($chatId, "❌ مدل فعالی یافت نشد، لطفاً بعداً تلاش کنید.");
            return;
        }

        $cost = (int) $model['cost_per_image'];

        if (!CreditService::hasEnoughCredit($internalId, $cost)) {
            $this->baleClient->sendMessage($chatId, "❌ اعتبار شما کافی نیست.\n💰 هزینه هر ویرایش: {$cost} اعتبار\n💳 لطفاً از بخش «شارژ اعتبار» حساب خود را افزایش دهید.");
            return;
        }

        $referenceId = 'ai_img_' . $internalId . '_' . time() . '_' . bin2hex(random_bytes(4));

        $this->baleClient->sendMessage($chatId, "⏳ در حال ویرایش تصویر... لطفاً چند لحظه صبر کنید.");

        $result = $aiService->generate([
            'model'  => $model['name'],
            'prompt' => $prompt,
            'image'  => $imageBase64,
        ]);

        if (isset($result['error'])) {
            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }
            Logger::error('Img2ImgHandler: AI edit failed', [
                'user_id' => $userId,
                'model'   => $model['name'],
                'error'   => $result['error'],
            ]);
            $this->baleClient->sendMessage($chatId, "⚠️ متأسفانه مشکلی در ویرایش تصویر پیش آمد. لطفاً دوباره تلاش کنید.");
            $this->logAiRequest($internalId, (int) $model['id'], $prompt, 'img2img', 'failed', $referenceId);
            return;
        }

        if ($tempFile && file_exists($tempFile)) {
            @unlink($tempFile);
        }

        $deducted = CreditService::deduct($internalId, $cost, $referenceId);
        if (!$deducted) {
            Logger::error('Img2ImgHandler: credit deduction failed', [
                'user_id' => $internalId, 'amount' => $cost, 'reference_id' => $referenceId,
            ]);
            $this->baleClient->sendMessage($chatId, "⚠️ مشکلی در کسر اعتبار پیش آمد. لطفاً دوباره تلاش کنید.");
            return;
        }

        $images = $result['images'];
        $caption = "✅ ویرایش شد با مدل {$model['name']}\n💰 هزینه: {$cost} اعتبار";

        foreach ($images as $url) {
            $this->baleClient->sendPhoto($chatId, $url, $caption);
            $caption = null;
        }

        $this->logAiRequest($internalId, (int) $model['id'], $prompt, 'img2img', 'success', $referenceId);
        $this->baleClient->sendMessage($chatId, "✅ تصویر با موفقیت ویرایش شد!", $this->getMainMenuKeyboard());
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

    private function storePhotoData(int $userId, string $base64, string $tempFile): void
    {
        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO bot_state (user_id, state, photo_base64, extra_data, updated_at)
                 VALUES (?, 'awaiting_edit_prompt', ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE photo_base64 = ?, extra_data = ?, updated_at = NOW()",
                [$userId, $base64, $tempFile, $base64, $tempFile]
            );
        } catch (\Throwable $e) {
            Logger::error('Img2ImgHandler: storePhotoData failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function getStoredPhotoData(int $userId): ?array
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query(
                "SELECT photo_base64, extra_data FROM bot_state WHERE user_id = ? AND state = 'awaiting_edit_prompt'",
                [$userId]
            );
            $row = $stmt->fetch();
            if (!$row) return null;
            return [
                'base64'    => $row['photo_base64'],
                'temp_file' => $row['extra_data'],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function clearPhotoData(int $userId): void
    {
        try {
            $db = Database::getInstance();
            $db->query("UPDATE bot_state SET photo_base64 = NULL, extra_data = NULL WHERE user_id = ?", [$userId]);
        } catch (\Throwable $e) {
            // Silent
        }
    }

    private function getUserState(int $userId): ?string
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT state FROM bot_state WHERE user_id = ?", [$userId]);
            $row = $stmt->fetch();
            return $row['state'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function setUserState(int $userId, string $state): void
    {
        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO bot_state (user_id, state, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE state = ?, updated_at = NOW()",
                [$userId, $state, $state]
            );
        } catch (\Throwable $e) {
            Logger::error('Img2ImgHandler: setUserState failed', ['user_id' => $userId, 'state' => $state, 'error' => $e->getMessage()]);
        }
    }

    private function clearUserState(int $userId): void
    {
        try {
            $db = Database::getInstance();
            $db->query("DELETE FROM bot_state WHERE user_id = ?", [$userId]);
        } catch (\Throwable $e) {
            // Silent
        }
    }

    private function getMainMenuKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => "🎨 ساخت تصویر"], ['text' => "🖼️ ویرایش عکس"]],
                [['text' => "👤 حساب من"], ['text' => "💳 شارژ اعتبار"]],
                [['text' => "❓ راهنما"]]
            ],
            'resize_keyboard' => true
        ];
    }
}