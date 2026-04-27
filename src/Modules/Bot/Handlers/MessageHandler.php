<?php

namespace Modules\Bot\Handlers;

use Exception;
use Database\Database;

class MessageHandler extends BaseHandler
{
    public function handle($update): void
    {
        try {
            $text = $update->getText();
            $contact = $update->getContact();

            if ($contact) {
                $this->handleContact($update, $contact);
                return;
            }

            // Fallback for unrecognized text or simple interaction
            $this->baleClient->sendMessage($update->getChatId(), "🤖 لطفاً از منوی زیر گزینه‌ای را انتخاب کنید:", $this->getMainMenuKeyboard());

        } catch (Exception $e) {
            error_log("MessageHandler Exception: " . $e->getMessage());
            $this->baleClient->sendMessage($update->getChatId(), "متأسفانه مشکلی پیش آمد. لطفاً دوباره تلاش کنید.");
        }
    }

    private function handleContact($update, array $contact): void
    {
        $baleId = $update->getChatId();
        $phoneNumber = $contact['phone_number'];

        $userModel = new \Modules\Bot\Models\User();
        $saved = $userModel->register($baleId, [
            'phone_number' => $phoneNumber,
            'first_name' => $contact['first_name'] ?? '',
            'last_name' => $contact['last_name'] ?? ''
        ]);

        if ($saved) {
            $this->baleClient->sendMessage($baleId, "✅ ثبت‌نام شما با موفقیت انجام شد!", $this->getMainMenuKeyboard());
        } else {
            $this->baleClient->sendMessage($baleId, "❌ متأسفانه در ثبت اطلاعات مشکلی پیش آمد.");
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