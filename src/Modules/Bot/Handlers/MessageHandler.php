<?php

namespace Modules\Bot\Handlers;

use Exception;
use Database\Database;

class MessageHandler extends BaseHandler
{
    public function handle(): void
    {
        try {
            $text = $this->update->getText();
            $contact = $this->update->getContact();

            if ($contact) {
                $this->handleContact($contact);
                return;
            }

            // Fallback for unrecognized text or simple interaction
            $this->sendMessage("🤖 لطفاً از منوی زیر گزینه‌ای را انتخاب کنید:", $this->getMainMenuKeyboard());

        } catch (Exception $e) {
            error_log("MessageHandler Exception: " . $e->getMessage());
            $this->sendMessage("متأسفانه مشکلی پیش آمد. لطفاً دوباره تلاش کنید.");
        }
    }

    private function handleContact(array $contact): void
    {
        $baleId = $this->update->getChatId();
        $phoneNumber = $contact['phone_number'];

        $saved = $this->userModel->register($baleId, [
            'phone_number' => $phoneNumber,
            'first_name' => $contact['first_name'] ?? '',
            'last_name' => $contact['last_name'] ?? ''
        ]);

        if ($saved) {
            $this->sendMessage("✅ ثبت‌نام شما با موفقیت انجام شد!", $this->getMainMenuKeyboard());
        } else {
            $this->sendMessage("❌ متأسفانه در ثبت اطلاعات مشکلی پیش آمد.");
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