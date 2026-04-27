<?php

namespace Modules\Bot\Handlers;

use Modules\AI\AIService;
use Modules\Bot\CreditService;
use Modules\Bot\Models\User;
use Database\Database;
use Database\Logger;

class Img2ImgHandler extends BaseHandler
{
    public function handle(): void
    {
        try {
            $chatId  = $this->update->getChatId();
            $userId  = $this->update->getUserId();
            $text    = $this->update->getText();
            $isCallback = $this->update->isCallback();

            // Step 1: If callback → ask for photo
            if ($isCallback) {
                $this->askForPhoto($chatId, $userId);
                return;
            }

            // Step 2: Keyboard button "🖼️ ویرایش عکس" pressed → start the flow
            if ($text === '🖼️ ویرایش عکس') {
                $this->askForPhoto($chatId, $userId);
                return;
            }

            // Step 3: Check state
            $state = $this->getUserState($userId);

            if ($state === 'awaiting_edit_photo') {
                $this->processPhoto($chatId, $userId);
                return;
            }

            if ($state === 'awaiting_edit_prompt') {
                $this->processPrompt($chatId, $userId, $text);
                return;
            }

            // Unknown state — redirect
            $this->sendMessage("🤖 لطفاً از منوی زیر گزینه‌ای را انتخاب کنید:", $this->getMainMenuKeyboard());
        } catch (\Throwable $e) {
            Logger::error('Img2ImgHandler exception', [
                'user_id' => $this->update->getUserId(),
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            $this->sendMessage("⚠️ متأسفانه مشکلی پیش آمد. لطفاً دوباره تلاش کنید.");
        }
    }

    /**
     * Ask user to send a photo.
     */
    private function askForPhoto(int $chatId, int $userId): void
    {
        $this->setUserState($userId, 'awaiting_edit_photo');

        $this->sendMessage(
            "🖼️ لطفاً عکسی که می‌خواهید ویرایش کنید را ارسال نمایید:"
        );
    }

    /**
     * Process the received photo: save temporarily, ask for prompt.
     */
    private function processPhoto(int $chatId, int $userId): void
    {
        if (!$this->update->hasPhoto()) {
            $this->sendMessage("⚠️ لطفاً یک عکس ارسال کنید.");
            return;
        }

        $fileId = $this->update->getPhotoFileId();
        if (!$fileId) {
            $this->sendMessage("⚠️ دریافت عکس با مشکل مواجه شد. لطفاً دوباره تلاش کنید.");
            return;
        }

        // 1. Get file info from Bale and download
        $fileInfo = $this->bale->getFile($fileId);
        if (!$fileInfo || !isset($fileInfo['file_path'])) {
            Logger::error('Img2ImgHandler: getFile failed', [
                'user_id'  => $userId,
                'file_id'  => $fileId,
            ]);
            $this->sendMessage("⚠️ دریافت عکس از سرور با مشکل مواجه شد. لطفاً دوباره تلاش کنید.");
            return;
        }

        // 2. Download the file content
        $fileContent = $this->bale->downloadFile($fileInfo['file_path']);
        if ($fileContent === null) {
            $this->sendMessage("⚠️ دانلود عکس با مشکل مواجه شد. لطفاً دوباره تلاش کنید.");
            return;
        }

        // 3. Save temporarily for later use
        $tempDir = sys_get_temp_dir() . '/bale_ai_edits';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempFile = $tempDir . '/' . $userId . '_' . time() . '.jpg';
        file_put_contents($tempFile, $fileContent);

        // 4. Convert to base64
        $base64 = base64_encode($fileContent);

        // 5. Store in bot_state for later retrieval
        $this->setUserState($userId, 'awaiting_edit_prompt');
        $this->storePhotoData($userId, $base64, $tempFile);

        $this->sendMessage(
            "✅ عکس دریافت شد.\n📝 لطفاً متن توضیح ویرایش مورد نظر خود را بنویسید:"
        );
    }

    /**
     * Process the edit prompt: check credit, call AI, send result.
     */
    private function processPrompt(int $chatId, int $userId, string $prompt): void
    {
        if (empty(trim($prompt))) {
            $this->sendMessage("⚠️ لطفاً یک متن معتبر وارد کنید.");
            return;
        }

        // 1. Get stored photo data and clear state
        $photoData = $this->getStoredPhotoData($userId);
        $this->clearUserState($userId);
        $this->clearPhotoData($userId);

        if (!$photoData || !isset($photoData['base64'])) {
            $this->sendMessage("⚠️ عکس ذخیره‌شده یافت نشد. لطفاً دوباره از اول شروع کنید.");
            return;
        }

        $imageBase64 = $photoData['base64'];
        $tempFile = $photoData['temp_file'] ?? null;

        // 2. Get user record
        $user = User::findByBaleId($userId);
        if (!$user) {
            $this->sendMessage("⚠️ کاربر یافت نشد. لطفاً ابتدا با /start ثبت‌نام کنید.");
            return;
        }
        $internalId = (int) $user['id'];

        // 3. Fetch default active model
        $aiService = new AIService();
        $model = $aiService->getFirstActiveModel();
        if (!$model) {
            Logger::error('Img2ImgHandler: no active AI model found');
            $this->sendMessage("❌ مدل فعالی یافت نشد، لطفاً بعداً تلاش کنید.");
            return;
        }

        $cost = (int) $model['cost_per_image'];

        // 4. Check credit
        if (!CreditService::hasEnoughCredit($internalId, $cost)) {
            $this->sendMessage(
                "❌ اعتبار شما کافی نیست.\n" .
                "💰 هزینه هر ویرایش: {$cost} اعتبار\n" .
                "💳 لطفاً از بخش «شارژ اعتبار» حساب خود را افزایش دهید."
            );
            return;
        }

        // 5. Generate reference for idempotency
        $referenceId = 'ai_img_' . $internalId . '_' . time() . '_' . bin2hex(random_bytes(4));

        // 6. Notify user
        $this->sendMessage("⏳ در حال ویرایش تصویر... لطفاً چند لحظه صبر کنید.");

        // 7. Call AI service with image
        $result = $aiService->generate([
            'model'  => $model['name'],
            'prompt' => $prompt,
            'image'  => $imageBase64,
        ]);

        // 8. Handle failure
        if (isset($result['error'])) {
            // N3: Clean up temp file even on failure
            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }
            Logger::error('Img2ImgHandler: AI edit failed', [
                'user_id' => $userId,
                'model'   => $model['name'],
                'error'   => $result['error'],
            ]);
            $this->sendMessage("⚠️ متأسفانه مشکلی در ویرایش تصویر پیش آمد. لطفاً دوباره تلاش کنید.");
            $this->logAiRequest($internalId, (int) $model['id'], $prompt, 'img2img', 'failed', $referenceId);
            return;
        }

        // 9. Clean up temp file on success
        if ($tempFile && file_exists($tempFile)) {
            @unlink($tempFile);
        }

        // 10. Deduct credit (idempotent)
        $deducted = CreditService::deduct($internalId, $cost, $referenceId);
        if (!$deducted) {
            Logger::error('Img2ImgHandler: credit deduction failed', [
                'user_id'      => $internalId,
                'amount'       => $cost,
                'reference_id' => $referenceId,
            ]);
            $this->sendMessage("⚠️ مشکلی در کسر اعتبار پیش آمد. لطفاً دوباره تلاش کنید.");
            return;
        }

        // 11. Send images one by one
        $images = $result['images'];
        $caption = "✅ ویرایش شد با مدل {$model['name']}\n💰 هزینه: {$cost} اعتبار";

        foreach ($images as $url) {
            $this->bale->sendPhoto($chatId, $url, $caption);
            $caption = null;
        }

        // 12. Log successful request
        $this->logAiRequest($internalId, (int) $model['id'], $prompt, 'img2img', 'success', $referenceId);

        // 13. Show menu
        $this->sendMessage("✅ تصویر با موفقیت ویرایش شد!", $this->getMainMenuKeyboard());
    }

    /**
     * Log AI request to ai_requests table.
     */
    private function logAiRequest(int $userId, int $modelId, string $prompt, string $imageType, string $status, string $referenceId): void
    {
        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, ?, ?, ?)",
                [$userId, $modelId, $prompt, $imageType, $status, $referenceId]
            );
        } catch (\Throwable $e) {
            Logger::error('Img2ImgHandler: logAiRequest failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Store photo base64 data for later use during prompt processing.
     */
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
            Logger::error('Img2ImgHandler: storePhotoData failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retrieve stored photo data.
     */
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

    /**
     * Clear stored photo data.
     */
    private function clearPhotoData(int $userId): void
    {
        try {
            $db = Database::getInstance();
            $db->query(
                "UPDATE bot_state SET photo_base64 = NULL, extra_data = NULL WHERE user_id = ?",
                [$userId]
            );
        } catch (\Throwable $e) {
            // Silent
        }
    }

    /**
     * Get user state from bot_state table.
     */
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

    /**
     * Set user state.
     */
    private function setUserState(int $userId, string $state): void
    {
        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO bot_state (user_id, state, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE state = ?, updated_at = NOW()",
                [$userId, $state, $state]
            );
        } catch (\Throwable $e) {
            Logger::error('Img2ImgHandler: setUserState failed', [
                'user_id' => $userId,
                'state'   => $state,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear user state.
     */
    private function clearUserState(int $userId): void
    {
        try {
            $db = Database::getInstance();
            $db->query("DELETE FROM bot_state WHERE user_id = ?", [$userId]);
        } catch (\Throwable $e) {
            // Silent
        }
    }

    /**
     * Build main menu keyboard.
     */
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