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
        }
    }
}