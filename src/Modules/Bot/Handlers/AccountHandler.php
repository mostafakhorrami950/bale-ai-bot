<?php
namespace Modules\Bot\Handlers;

use Modules\Bot\Models\User;
use Modules\Memory\MemoryManager;
use Database\Database;

class AccountHandler extends BaseHandler
{
    public function handle($update): void
    {
        $chatId = $update->getChatId();
        $userId = $update->getUserId();
        $callbackData = $update->getCallbackData();
        
        if (!$chatId || !$userId) return;

        // Membership check
        if (!$this->checkMembership($userId, $chatId)) return;
        
        try {
            $userData = User::findByBaleId($userId);
            
            if ($userData) {
                $credits = $userData['credits'] ?? 0;
                $phone = $userData['phone_number'] ?? 'نامشخص';
                $firstName = $userData['first_name'] ?? '';
                $lastName = $userData['last_name'] ?? '';
                $name = trim($firstName . ' ' . $lastName);
                
                $message = "👤 **حساب کاربری شما**\n\n";
                if ($name) {
                    $message .= "🧑 نام: {$name}\n";
                }
                $message .= "📱 شماره: {$phone}\n";
                $message .= "💎 اعتبار: " . number_format($credits) . " کردیت\n";
                $message .= "🆔 شناسه: {$userId}\n";

                // Memory info (if module enabled) — WITH CLEAR EXAMPLES
                $memoryManager = new MemoryManager();
                if ($memoryManager->isEnabled()) {
                    $internalId = $this->resolveInternalId($userId);
                    if ($internalId) {
                        $memoryCount = $memoryManager->getMemoryCount($internalId);
                        $message .= "\n🧠 **حافظه**: {$memoryCount} مورد ذخیره شده\n";
                        $message .= "━━━━━━━━━━━━━━━━━━\n";
                        $message .= "📌 **چگونه از حافظه استفاده کنم؟**\n\n";
                        $message .= "**➕ اضافه کردن اطلاعات:**\n";
                        $message .= "در حین گفتگو، این جمله‌ها را به ربات بگویید:\n";
                        $message .= "「یادت باشه اسم من علی است」\n";
                        $message .= "「به خاطر بسپار من برنامه‌نویس هستم」\n";
                        $message .= "「ذخیره کن رنگ مورد علاقه‌ام آبی است」\n";
                        $message .= "「فراموش نکن تولد من ۱۵ فروردین است」\n\n";
                        $message .= "🤖 ربات همچنین به طور خودکار اطلاعات مهم\n";
                        $message .= "(نام، سن، شغل، علایق و ...) را از\n";
                        $message .= "گفتگوهای شما استخراج و ذخیره می‌کند.\n\n";
                        $message .= "**👁️ مشاهده حافظه:**\n";
                        $message .= "دکمه «🧠 حافظه من» را بزنید\n";
                        $message .= "یا دستور `/حافظه` را ارسال کنید.\n\n";
                        $message .= "**🗑️ پاک کردن حافظه:**\n";
                        $message .= "دکمه «🗑️ پاک کردن حافظه» را بزنید\n";
                        $message .= "یا دستور `/حذف_حافظه` را ارسال کنید.\n\n";
                        $message .= "**💡 نکته:** حافظه در چت‌های بعدی به\n";
                        $message .= "هوش مصنوعی گفته می‌شود تا پاسخ‌های\n";
                        $message .= "شخصی‌سازی‌شده دریافت کنید.\n";
                        $message .= "━━━━━━━━━━━━━━━━━━\n";
                    }
                }
                
                $message .= "\n🔹 برای افزایش اعتبار از گزینه «💳 خرید اعتبار» استفاده کنید.";
            } else {
                $message = "⚠️ حساب کاربری یافت نشد. لطفاً /start را بزنید.";
            }
            
            $this->baleClient->sendMessage($chatId, $message);
        } catch (\Exception $e) {
            error_log("AccountHandler ERROR: " . $e->getMessage());
            $this->baleClient->sendMessage($chatId, "⚠️ متأسفانه مشکلی پیش آمد.");
        }
    }

    /**
     * Resolve internal user ID from Bale user ID.
     */
    private function resolveInternalId(int $baleUserId): ?int
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
}