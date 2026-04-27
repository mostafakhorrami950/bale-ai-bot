<?php

namespace Modules\Bot\Handlers;

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

            if (!$callbackId) {
                return;
            }

            // Logic for specific callbacks would go here
            switch ($callbackData) {
                case 'help':
                    $this->baleClient->sendMessage($update->getChatId(), "❓ راهنما:\nاین ربات به شما کمک می‌کند با هوش مصنوعی تصویر بسازید.");
                    break;
            }

        } catch (Exception $e) {
            error_log("CallbackHandler Exception: " . $e->getMessage());
        }
    }
}