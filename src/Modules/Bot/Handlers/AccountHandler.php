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

                // Memory info (if module enabled)
                $memoryManager = new MemoryManager();
                if ($memoryManager->isEnabled()) {
                    $internalId = $this->resolveInternalId($userId);
                    if ($internalId) {
                        $memoryCount = $memoryManager->getMemoryCount($internalId);
                        $message .= "\n🧠 **حافظه**: {$memoryCount} مورد ذخیره شده\n";
                        $message .= "   📌 با «یادت باشه [متن]» می‌توانید اطلاعات ذخیره کنید\n";
                        $message .= "   📋 با «🧠 حافظه من» حافظه خود را ببینید\n";
                        $message .= "   🗑 با «🗑 پاک کردن حافظه» همه را پاک کنید\n";
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