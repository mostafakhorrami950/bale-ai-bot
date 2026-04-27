<?php

namespace Modules\Bot\Handlers;

use Modules\AI\AIService;
use Modules\Bot\CreditService;
use Modules\Bot\Models\User;
use Database\Database;
use Database\Logger;

class ImageHandler extends BaseHandler
{
    public function handle(): void
    {
        try {
            $chatId  = $this->update->getChatId();
            $userId  = $this->update->getUserId();
            $text    = $this->update->getText();
            $isCallback = $this->update->isCallback();

            // Step 1: If callback → came from inline button "generate_image"
            if ($isCallback) {
                $this->askForPrompt($chatId, $userId);
                return;
            }

            // Step 2: Keyboard button "🎨 ساخت تصویر" pressed → start the flow
            if ($text === '🎨 ساخت تصویر') {
                $this->askForPrompt($chatId, $userId);
                return;
            }

            // Step 3: Check state — this should be a text message with prompt
            $state = $this->getUserState($userId);

            if ($state === 'awaiting_image_prompt') {
                $this->processPrompt($chatId, $userId, $text);
                return;
            }

            // Unknown state — redirect to menu
            $this->sendMessage("🤖 لطفاً از منوی زیر گزینه‌ای را انتخاب کنید:", $this->getMainMenuKeyboard());
        } catch (\Throwable $e) {
            Logger::error('ImageHandler exception', [
                'user_id' => $this->update->getUserId(),
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            $this->sendMessage("⚠️ متأسفانه مشکلی پیش آمد. لطفاً دوباره تلاش کنید.");
        }
    }

    /**
     * Ask user to send the prompt text.
     */
    private function askForPrompt(int $chatId, int $userId): void
    {
        // Set state to awaiting prompt
        $this->setUserState($userId, 'awaiting_image_prompt');

        $this->sendMessage(
            "🎨 لطفاً متن تصویر مورد نظر خود را بنویسید:"
        );
    }

    /**
     * Process the prompt: check credit, call AI, send result.
     */
    private function processPrompt(int $chatId, int $userId, string $prompt): void
    {
        if (empty(trim($prompt))) {
            $this->sendMessage("⚠️ لطفاً یک متن معتبر وارد کنید.");
            return;
        }

        // Clear state immediately to prevent double-processing
        $this->clearUserState($userId);

        // 1. Get user record (need internal id for credit service)
        $user = User::findByBaleId($userId);
        if (!$user) {
            $this->sendMessage("⚠️ کاربر یافت نشد. لطفاً ابتدا با /start ثبت‌نام کنید.");
            return;
        }
        $internalId = (int) $user['id'];

        // 2. Fetch default active model
        $aiService = new AIService();
        $model = $aiService->getFirstActiveModel();
        if (!$model) {
            Logger::error('ImageHandler: no active AI model found');
            $this->sendMessage("❌ مدل فعالی یافت نشد، لطفاً بعداً تلاش کنید.");
            return;
        }

        $cost = (int) $model['cost_per_image'];

        // 3. Check credit
        if (!CreditService::hasEnoughCredit($internalId, $cost)) {
            $this->sendMessage(
                "❌ اعتبار شما کافی نیست.\n" .
                "💰 هزینه هر تصویر: {$cost} اعتبار\n" .
                "💳 لطفاً از بخش «شارژ اعتبار» حساب خود را افزایش دهید."
            );
            return;
        }

        // 4. Generate reference for idempotency
        $referenceId = 'ai_txt_' . $internalId . '_' . time() . '_' . bin2hex(random_bytes(4));

        // 5. Notify user that generation is in progress
        $this->sendMessage("⏳ در حال ساخت تصویر... لطفاً چند لحظه صبر کنید.");

        // 6. Call AI service
        $result = $aiService->generate([
            'model'  => $model['name'],
            'prompt' => $prompt,
        ]);

        // 7. Handle failure
        if (isset($result['error'])) {
            Logger::error('ImageHandler: AI generation failed', [
                'user_id' => $userId,
                'model'   => $model['name'],
                'error'   => $result['error'],
            ]);
            $this->sendMessage("⚠️ متأسفانه مشکلی در ساخت تصویر پیش آمد. لطفاً دوباره تلاش کنید.");
            $this->logAiRequest($internalId, (int) $model['id'], $prompt, 'text2img', 'failed', $referenceId);
            return;
        }

        // 8. Send images first, then deduct credit (N2: reverse order to prevent charging on send failure)
        $images = $result['images'];
        $caption = "✅ ساخته شد با مدل {$model['name']}\n💰 هزینه: {$cost} اعتبار";

        $allSent = true;
        foreach ($images as $url) {
            $response = $this->bale->sendPhoto($chatId, $url, $caption);
            if (!isset($response['ok']) || $response['ok'] !== true) {
                $allSent = false;
                Logger::error('ImageHandler: sendPhoto failed', [
                    'user_id' => $userId,
                    'url'     => $url,
                    'error'   => $response['description'] ?? 'Unknown',
                ]);
            }
            // Only caption on first image
            $caption = null;
        }

        // 9. Deduct credit only if images were sent successfully
        if ($allSent) {
            $deducted = CreditService::deduct($internalId, $cost, $referenceId);
            if (!$deducted) {
                Logger::error('ImageHandler: credit deduction failed AFTER sending images', [
                    'user_id'      => $internalId,
                    'amount'       => $cost,
                    'reference_id' => $referenceId,
                ]);
                // Image was already sent — log error but don't tell user
            }
        } else {
            Logger::warning('ImageHandler: some images failed to send, not charging', [
                'user_id' => $userId,
            ]);
            // Don't deduct if images weren't sent
        }

        // 10. Log successful request (mark success even if deduct failed — image was sent)
        $this->logAiRequest($internalId, (int) $model['id'], $prompt, 'text2img', 'success', $referenceId);

        // 11. Show menu again
        $this->sendMessage("✅ تصویر با موفقیت ساخته شد!", $this->getMainMenuKeyboard());
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
            Logger::error('ImageHandler: logAiRequest failed', [
                'error' => $e->getMessage(),
            ]);
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
            Logger::error('ImageHandler: setUserState failed', [
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