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
            $callbackData = $update->getCallbackData();
            $contact = $update->getContact();
            $chatId = $update->getChatId();
            $userId = $update->getUserId();

            if ($contact) {
                $this->handleContact($update, $contact);
                return;
            }

            // Membership check for all non-registration actions
            if ($userId && $chatId) {
                if (!$this->checkMembership($userId, $chatId)) return;
            }

            // Handle help callback or text
            if ($callbackData === 'help' || $text === "\xE2\x9D\x93 راهنما") {
                $this->showHelp($chatId);
                return;
            }

            // Fallback — show main menu
            $this->baleClient->sendMessage($chatId, "🤖 لطفاً از منوی زیر گزینه‌ای را انتخاب کنید:", $this->getMainMenuKeyboard());

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
            'first_name' => $contact['first_name'] ?? $update->getFirstName() ?? '',
            'last_name' => $contact['last_name'] ?? '',
            'username' => $update->getUsername() ?? ''
        ]);

        if ($saved) {
            // Send registration success, then show MAIN MENU (not reply keyboard)
            $this->baleClient->sendMessage($baleId, "✅ ثبت‌نام شما با موفقیت انجام شد!");
            $this->baleClient->sendMessage($baleId, "🤖 به ربات خوش آمدید. لطفاً از منوی زیر استفاده کنید:", $this->getMainMenuKeyboard());
        } else {
            $this->baleClient->sendMessage($baleId, "❌ متأسفانه در ثبت اطلاعات مشکلی پیش آمد.");
        }
    }

    /**
     * Show help text + image from settings table.
     */
    private function showHelp(int $chatId): void
    {
        try {
            $db = Database::getInstance();
            $rows = $db->query("SELECT key_name, value FROM settings WHERE key_name IN ('help_text', 'help_image')")->fetchAll();
            $settings = [];
            foreach ($rows as $r) {
                $settings[$r['key_name']] = $r['value'];
            }

            $helpText = $settings['help_text'] ?? "🤖 **راهنمای ربات**\n\n"
                . "🎨 **ساخت تصویر**: با استفاده از هوش مصنوعی تصویر بسازید.\n"
                . "🖼 **ویرایش عکس**: عکس خود را آپلود کرده و با توضیحات ویرایش کنید.\n"
                . "💬 **چت با هوش مصنوعی**: با مدل‌های مختلف گفتگو کنید.\n"
                . "👤 **حساب کاربری**: موجودی و تاریخچه خود را مشاهده کنید.\n"
                . "💳 **خرید اعتبار**: اعتبار خود را افزایش دهید.\n\n"
                . "📞 پشتیبانی: @mobix_tube";

            $helpImage = $settings['help_image'] ?? null;

            if (!empty($helpImage) && filter_var($helpImage, FILTER_VALIDATE_URL)) {
                // Send photo with caption
                $caption = strip_tags($helpText);
                $this->baleClient->sendPhoto($chatId, $helpImage, $caption);
            } else {
                $this->baleClient->sendMessage($chatId, $helpText);
            }
        } catch (\Throwable $e) {
            $this->baleClient->sendMessage($chatId, "❌ خطا در بارگذاری راهنما.");
        }
    }

    /**
     * Unified 6-button main menu (inline keyboard with callback_data).
     */
    public static function getMainMenuKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => "\xF0\x9F\x8E\xA8 ساخت تصویر", 'callback_data' => 'generate_image'], ['text' => "\xF0\x9F\x96\xBC\xEF\xB8\x8F ویرایش عکس", 'callback_data' => 'edit_image']],
                [['text' => "\xF0\x9F\x92\xAC چت با هوش مصنوعی", 'callback_data' => 'start_chat'], ['text' => "\xF0\x9F\x8E\xAC ساخت ویدئو با هوش مصنوعی", 'callback_data' => 'generate_video']],
                [['text' => "\xF0\x9F\x91\xA4 حساب کاربری", 'callback_data' => 'account'], ['text' => "\xF0\x9F\x92\xB3 خرید اعتبار", 'callback_data' => 'buy_credit']],
                [['text' => "\xE2\x9D\x93 راهنما", 'callback_data' => 'help']],
            ]
        ];
    }
}