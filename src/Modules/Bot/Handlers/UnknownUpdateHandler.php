<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\BaleClient;

class UnknownUpdateHandler extends BaseHandler
{
    protected BaleClient $baleClient;

    public function __construct(BaleClient $baleClient)
    {
        $this->baleClient = $baleClient;
    }

    public function handle($update): void
    {
        if (method_exists($update, 'isCallback') && $update->isCallback()) {
            $callbackId = method_exists($update, 'getCallbackId') ? $update->getCallbackId() : null;
            if ($callbackId) {
                $this->baleClient->answerCallbackQuery($callbackId);
            }
        }
    }
}