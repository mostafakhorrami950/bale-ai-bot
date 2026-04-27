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
            $isCallback = $update->isCallback();

            if ($isCallback) {
                $this->askForPrompt($chatId, $userId);
                return;
            }

            if ($text === '🎨 ساخت تصویر') {
                $this->askForPrompt($chatId, $userId);
                return;
            }

            $state = $this->getUserState($userId);

            if ($state === 'awaiting_image_prompt') {
                $this->processPrompt($chatId, $userId, $text);
                return;
            }

            $this->baleClient->sendMessage($chatId, "🤖 لطفاً از منوی زیر گزینه‌ای را انتخاب کنید:", $this->getMainMenuKeyboard());
        } catch (\Throwable $e) {
            Logger::error('ImageHandler exception', [
                'user_id' => $update->getUserId(),
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            $this->baleClient->sendMessage($update->getChatId(), "⚠️ متأسفانه مشکلی پیش آمد. لطفاً دوباره تلاش کنید.");
        }
    }

    /**
     * Resolve internal user.id from Bale user ID.
     */
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

    private function askForPrompt(int $chatId, int $userId): void
    {
        $internalId = $this->resolveUserId($userId);
        if ($internalId) {
            Database::getInstance()->query(
                "INSERT INTO bot_state (user_id, state, updated_at) VALUES (?, 'awaiting_image_prompt', NOW())
                 ON DUPLICATE KEY UPDATE state='awaiting_image_prompt', updated_at=NOW()",
                [$internalId]
            );
        }
        $this->baleClient->sendMessage($chatId, "🎨 لطفاً متن تصویر مورد نظر خود را بنویسید:");
    }

    private function processPrompt(int $chatId, int $userId, string $prompt): void
    {
        if (empty(trim($prompt))) {
            $this->baleClient->sendMessage($chatId, "⚠️ لطفاً یک متن معتبر وارد کنید.");
            return;
        }

        $user = User::findByBaleId($userId);
        if (!$user) {
            $this->baleClient->sendMessage($chatId, "⚠️ کاربر یافت نشد. لطفاً ابتدا با /start ثبت‌نام کنید.");
            return;
        }
        $internalId = (int) $user['id'];

        // Clear state
        $this->clearUserState($internalId);

        $aiService = new AIService();
        $model = $aiService->getFirstActiveModel();
        if (!$model) {
            Logger::error('ImageHandler: no active AI model found');
            $this->baleClient->sendMessage($chatId, "❌ مدل فعالی یافت نشد، لطفاً بعداً تلاش کنید.");
            return;
        }

        $cost = (int) $model['cost_per_image'];

        if (!CreditService::hasEnoughCredit($internalId, $cost)) {
            $this->baleClient->sendMessage($chatId, "❌ اعتبار شما کافی نیست.\n💰 هزینه هر تصویر: {$cost} اعتبار\n💳 لطفاً از بخش «شارژ اعتبار» حساب خود را افزایش دهید.");
            return;
        }

        $referenceId = 'ai_txt_' . $internalId . '_' . time() . '_' . bin2hex(random_bytes(4));

        $this->baleClient->sendMessage($chatId, "⏳ در حال ساخت تصویر... لطفاً چند لحظه صبر کنید.");

        $result = $aiService->generate([
            'model'  => $model['name'],
            'prompt' => $prompt,
        ]);

        if (isset($result['error'])) {
            Logger::error('ImageHandler: AI generation failed', [
                'user_id' => $userId,
                'model'   => $model['name'],
                'error'   => $result['error'],
            ]);
            $this->baleClient->sendMessage($chatId, "⚠️ متأسفانه مشکلی در ساخت تصویر پیش آمد. لطفاً دوباره تلاش کنید.");
            $this->logAiRequest($internalId, (int) $model['id'], $prompt, 'text2img', 'failed', $referenceId);
            return;
        }

        $images = $result['images'];
        $caption = "✅ ساخته شد با مدل {$model['name']}\n💰 هزینه: {$cost} اعتبار";

        $allSent = true;
        foreach ($images as $url) {
            $response = $this->baleClient->sendPhoto($chatId, $url, $caption);
            if (!isset($response['ok']) || $response['ok'] !== true) {
                $allSent = false;
                Logger::error('ImageHandler: sendPhoto failed', [
                    'user_id' => $userId,
                    'url'     => $url,
                    'error'   => $response['description'] ?? 'Unknown',
                ]);
            }
            $caption = null;
        }

        if ($allSent) {
            $deducted = CreditService::deduct($internalId, $cost, $referenceId);
            if (!$deducted) {
                Logger::error('ImageHandler: credit deduction failed AFTER sending images', [
                    'user_id'      => $internalId,
                    'amount'       => $cost,
                    'reference_id' => $referenceId,
                ]);
            }
        } else {
            Logger::warning('ImageHandler: some images failed to send, not charging', ['user_id' => $userId]);
        }

        $this->logAiRequest($internalId, (int) $model['id'], $prompt, 'text2img', 'success', $referenceId);

        $this->clearUserState($internalId);
        $this->baleClient->sendMessage($chatId, "✅ تصویر با موفقیت ساخته شد!", $this->getMainMenuKeyboard());
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
            Logger::error('ImageHandler: logAiRequest failed', ['error' => $e->getMessage()]);
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

    private function clearUserState(int $internalId): void
    {
        try {
            $db = Database::getInstance();
            $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
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
}