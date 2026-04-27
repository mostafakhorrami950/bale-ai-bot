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
        error_log("DEBUG: Dispatcher::dispatch() STARTED. Update type: " . gettype($update));

        try {
            $handler = $this->router->resolve($update);
            error_log("DEBUG: Dispatcher about to execute handler: " . get_class($handler));
            $handler->handle($update);
            error_log("DEBUG: Dispatcher handler executed successfully");
        } catch (\Throwable $e) {
            $msg = date('[Y-m-d H:i:s]') . " DISPATCHER FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
            file_put_contents(__DIR__ . '/../../../public/debug.txt', $msg, FILE_APPEND);
            error_log("DISPATCHER FATAL: " . $e->getMessage());
        }
    }
}