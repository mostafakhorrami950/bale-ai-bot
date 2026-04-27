<?php
namespace Modules\Bot\Handlers;

class AccountHandler extends BaseHandler
{
    public function handle($update): void
    {
        $chatId = $update->getChatId();
        $userId = $update->getUserId();
        
        if (!$chatId || !$userId) return;
        
        try {
            $userData = \Modules\Bot\Models\User::findByBaleId($userId);
            
            if ($userData) {
                $credits = $userData['credits'] ?? 0;
                $phone = $userData['phone_number'] ?? 'نامشخص';
                
                $message = "👤 **حساب کاربری شما**\n\n";
                $message .= "📱 شماره: {$phone}\n";
                $message .= "💎 اعتبار: " . number_format($credits) . " کردیت\n";
                $message .= "🆔 شناسه: {$userId}\n";
            } else {
                $message = "⚠️ حساب کاربری یافت نشد. لطفاً /start را بزنید.";
            }
            
            $this->baleClient->sendMessage($chatId, $message);
        } catch (\Exception $e) {
            error_log("AccountHandler ERROR: " . $e->getMessage());
            $this->baleClient->sendMessage($chatId, "⚠️ متأسفانه مشکلی پیش آمد.");
        }
    }
}