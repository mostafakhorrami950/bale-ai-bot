<?php
namespace Modules\Bot\Handlers;

use Modules\Bot\Models\User;
use Modules\Memory\MemoryManager;
use Database\Database;
use Core\BotTextService;

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
                
                $nameLine = $name ? "🧑 نام: {$name}\n" : '';
                $creditFormatted = number_format((float)$credits, 10);
                
                // Memory info (if module enabled)
                $memorySection = '';
                $memoryManager = new MemoryManager();
                if ($memoryManager->isEnabled()) {
                    $internalId = $this->resolveInternalId($userId);
                    if ($internalId) {
                        $memoryCount = $memoryManager->getMemoryCount($internalId);
                        $memorySection = BotTextService::get('account_memory_header', ['count' => $memoryCount]);
                    }
                }
                
                $message = BotTextService::get('account_title', [
                    'name_line' => $nameLine,
                    'phone' => $phone,
                    'credits' => $creditFormatted,
                    'user_id' => $userId,
                    'memory_section' => $memorySection,
                ]);
            } else {
                $message = BotTextService::get('account_not_found');
            }
            
            // Add memory inline buttons if module enabled
            $memoryManager = new MemoryManager();
            $keyboard = null;
            if ($memoryManager->isEnabled()) {
                $internalId = $this->resolveInternalId($userId);
                $isDisabled = $internalId ? $memoryManager->isDisabledForUser($internalId) : false;
                
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '🧠 حافظه من', 'callback_data' => 'show_memory']],
                        [['text' => '➕ افزودن به حافظه', 'callback_data' => 'add_memory']],
                        [['text' => $isDisabled ? '✅ فعال کردن حافظه' : '🚫 غیرفعال کردن حافظه', 'callback_data' => 'toggle_memory']],
                        [['text' => '🗑️ پاک کردن حافظه', 'callback_data' => 'clear_memory']],
                    ]
                ];
            }
            
            $this->baleClient->sendMessage($chatId, $message, $keyboard);
        } catch (\Exception $e) {
            error_log("AccountHandler ERROR: " . $e->getMessage());
            $this->baleClient->sendMessage($chatId, BotTextService::get('account_error'));
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
