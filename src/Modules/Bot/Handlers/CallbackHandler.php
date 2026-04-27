<?php

namespace Modules\Bot\Handlers;

use Exception;

class CallbackHandler extends BaseHandler
{
    /**
     * Handles callback queries (inline button clicks).
     */
    public function handle(): void
    {
        try {
            $callbackData = $this->update->getCallbackData();
            $callbackId = $this->update->getCallbackId();

            if (!$callbackId) {
                return;
            }

            // Always verify callback is answered (handled by Dispatcher/Router primarily but extra safety here)
            // Logic for specific callbacks would go here
            
            switch ($callbackData) {
                case 'help':
                    $this->sendMessage("❓ راهنما:\nاین ربات به شما کمک می‌کند با هوش مصنوعی تصویر بسازید.");
                    break;
                // Add other cases as needed
            }

        } catch (Exception $e) {
            error_log("CallbackHandler Exception: " . $e->getMessage());
        }
    }
}