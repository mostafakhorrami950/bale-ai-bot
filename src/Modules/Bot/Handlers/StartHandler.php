<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\Models\User;

class StartHandler extends BaseHandler
{
    public function handle($update): void
    {
        $chatId = $update->getChatId();
        $userId = $update->getUserId();

        error_log("DEBUG: StartHandler::handle() CALLED. Chat ID: " . ($chatId ?? 'none'));

        if (!$chatId) {
            return;
        }

        try {
            // Check if user is registered
            $isRegistered = User::isRegistered($userId);

            if (!$isRegistered) {
                // Ask for phone number
                $keyboard = [
                    'keyboard' => [
                        [
                            ['text' => '📱 ارسال شماره', 'request_contact' => true]
                        ]
                    ],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true
                ];

                $this->baleClient->sendMessage(
                    $chatId,
                    "سلام! 👋\n\nبه ربات هوش مصنوعی خوش آمدید.\nبرای استفاده از خدمات، لطفاً شماره خود را تأیید کنید.",
                    $keyboard
                );
            } else {
                // User already registered — show main menu
                $keyboard = [
                    'keyboard' => [
                        [
                            ['text' => '🎨 ساخت تصویر'],
                            ['text' => '🖼 ویرایش عکس']
                        ],
                        [
                            ['text' => '💳 شارژ اعتبار'],
                            ['text' => '👤 حساب من']
                        ]
                    ],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => false
                ];

                $this->baleClient->sendMessage(
                    $chatId,
                    "سلام مجدد! 👋\nچه کاری میتونم برات انجام بدم؟",
                    $keyboard
                );
            }
        } catch (\Exception $e) {
            error_log("StartHandler ERROR: " . $e->getMessage());
            $this->baleClient->sendMessage(
                $chatId,
                "متأسفانه مشکلی پیش آمد. لطفاً دوباره تلاش کنید."
            );
        }
    }
}