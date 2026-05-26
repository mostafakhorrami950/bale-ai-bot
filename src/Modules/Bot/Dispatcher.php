<?php

namespace Modules\Bot;

use Database\Logger;

class Dispatcher
{
    private $update;
    private $router;

    public function __construct(Update $update)
    {
        $this->update = $update;
        $this->router = new Router();
    }

    /**
     * Dispatches the update to the resolved handler.
     */
    public function dispatch($update): void
    {
        try {
            $handler = $this->router->resolve($update);
            $handler->handle($update);
        } catch (\Throwable $e) {
            Logger::error('Dispatcher fatal error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine()
            ]);
            
            // Also log to app_errors table for admin visibility
            try {
                $db = \Database\Database::getInstance();
                $baleUserId = null;
                try {
                    if (method_exists($update, 'getUserId')) {
                        $baleUserId = $update->getUserId();
                    }
                } catch (\Throwable $ignored) {}
                $db->query(
                    "INSERT INTO app_errors (error_type, error_message, error_trace, bale_user_id) VALUES (?, ?, ?, ?)",
                    [
                        'dispatcher_error',
                        $e->getMessage(),
                        $e->getTraceAsString(),
                        $baleUserId
                    ]
                );
            } catch (\Throwable $ignored) {}
            
            // Tell user to use /start
            if ($baleUserId) {
                try {
                    $bale = new \Modules\Bot\BaleClient();
                    $bale->sendMessage($baleUserId, "⚠️ خطایی در پردازش پیام شما رخ داد.\nبرای رفع مشکل، لطفاً دستور /start را ارسال کنید.");
                } catch (\Throwable $ignored) {}
            }
        }
    }
}
