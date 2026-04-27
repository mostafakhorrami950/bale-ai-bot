<?php

namespace Modules\Bot\Handlers;

use Exception;
use Modules\Bot\Models\User;
use Database\Database;

class StartHandler extends BaseHandler
{
    /**
     * Handles the /start command.
     */
    public function handle($update)
    {
        error_log("DEBUG: StartHandler::handle() CALLED. Chat ID: " . ($update->getChatId() ?? 'none'));
        $chatId = $update->getChatId();
        $userId = $update->getUserId();

        $userModel = new \Modules\Bot\Models\User();
        $user = $userModel->getByBaleId($userId);

        if (!$user || !$user['is_registered']) {
            // Step A: Set awaiting_contact state
            Database::getInstance()->execute(
                "INSERT INTO bot_state (user_id, state, updated_at) VALUES (?, 'awaiting_contact', NOW()) ON DUPLICATE KEY UPDATE state='awaiting_contact', updated_at=NOW()",
                [$userId]
            );

            $welcomeMessage = "سلام! خوش آمدید. برای استفاده از ربات لطفا ابتدا با فشردن دکمه زیر شماره همراه خود را به اشتراک بگذارید.";
            $keyboard = [
                'keyboard' => [
                    [
                        ['text' => "📱 اشتراک‌گذاری شماره موبایل", 'request_contact' => true]
                    ]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true
            ];
        } else {
            $welcomeMessage = "به ربات خوش آمدید! چه کمکی از من ساخته است؟";
            $keyboard = [
                'keyboard' => [
                    [['text' => "🎨 ساخت تصویر"], ['text' => "🖼️ ویرایش عکس"]],
                    [['text' => "👤 حساب من"], ['text' => "💳 شارژ اعتبار"]],
                    [['text' => "❓ راهنما"]]
                ],
                'resize_keyboard' => true
            ];
        }

        // Fix 1: Enforce Bale API Response Checking
        $result = $this->baleClient->sendMessage($chatId, $welcomeMessage, $keyboard);
        if (!$result) {
            $this->logger->error('StartHandler: sendMessage failed for user ' . $userId);
            return;
        }
        $this->logger->info('StartHandler: /start processed for user ' . $userId);
    }
}