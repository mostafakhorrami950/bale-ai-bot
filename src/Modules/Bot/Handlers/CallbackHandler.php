<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\Models\Channel;
use Exception;

class CallbackHandler extends BaseHandler
{
    /**
     * Handles callback queries (inline button clicks).
     */
    public function handle($update): void
    {
        try {
            $callbackData = $update->getCallbackData();
            $callbackId = $update->getCallbackId();
            $chatId = $update->getChatId();
            $userId = $update->getUserId();

            if (!$callbackId) {
                return;
            }

            switch ($callbackData) {
                case 'help':
                    $this->baleClient->sendMessage($chatId, "❓ راهنما:\nاین ربات به شما کمک می‌کند با هوش مصنوعی تصویر بسازید.");
                    break;

                case 'check_membership':
                    $passed = $this->checkMembership($userId, $chatId);
                    if ($passed) {
                        $this->baleClient->sendMessage($chatId, "✅ عضویت شما تأیید شد. از منوی زیر استفاده کنید:");
                    }
                    break;
            }

        } catch (Exception $e) {
            error_log("CallbackHandler Exception: " . $e->getMessage());
        }
    }
}
