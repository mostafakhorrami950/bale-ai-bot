<?php

namespace Modules\Bot;

use Database\Logger;

class Dispatcher
{
    private $update;

    public function __construct(Update $update)
    {
        $this->update = $update;
    }

    /**
     * Dispatches the update to the resolved handler class.
     */
    public function dispatch(string $handlerClass): void
    {
        error_log("DEBUG: Dispatcher::dispatch() STARTED. Update type: " . gettype($this->update));
        try {
            if (!class_exists($handlerClass)) {
                throw new \Exception("Handler class $handlerClass not found");
            }

            $handler = new $handlerClass($this->update);
            error_log("DEBUG: Dispatcher about to execute handler: " . get_class($handler));

            if (!method_exists($handler, 'handle')) {
                throw new \Exception("Handle method not found in $handlerClass");
            }

            $handler->handle();
            error_log("DEBUG: Dispatcher handler executed successfully");

        } catch (\Throwable $e) {
            $this->handleFailure($e, $handlerClass);
        }
    }

    private function handleFailure(\Throwable $e, string $handlerClass): void
    {
        // Log error with detail
        $errorMsg = "Dispatcher Error in $handlerClass: " . $e->getMessage();
        Logger::logUpdate(
            $this->update->getId() ?? 0, 
            $this->update->getUserId() ?? 0, 
            $errorMsg
        );

        // Fail-safe user response
        try {
            $chatId = $this->update->getChatId();
            if ($chatId) {
                $baleClient = new BaleClient();
                $baleClient->sendMessage(
                    $chatId,
                    "⚠️ متأسفانه مشکلی در پردازش درخواست شما پیش آمد. لطفاً دوباره تلاش کنید."
                );
            }
        } catch (\Exception $fallbackError) {
            // Complete silence if even fallback fails, but we don't break the webhook
        }
    }
}