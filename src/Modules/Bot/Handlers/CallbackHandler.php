<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\Models\Channel;
use Exception;
use Core\BotTextService;

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
                    $this->baleClient->sendMessage($chatId, BotTextService::get('help_callback'));
                    break;

                case 'check_membership':
                    // Delete the previous membership message
                    $prevMsgId = $this->getAndDeleteMembershipMessageId($chatId);
                    if ($prevMsgId) {
                        $this->baleClient->deleteMessage($chatId, $prevMsgId);
                    }
                    $passed = $this->checkMembership($userId, $chatId);
                    if ($passed) {
                        $this->baleClient->sendMessage($chatId, BotTextService::get('membership_confirmed'), MessageHandler::getMainMenuKeyboard());
                    }
                    break;
            }

        } catch (Exception $e) {
            error_log("CallbackHandler Exception: " . $e->getMessage());
        }
    }
}
