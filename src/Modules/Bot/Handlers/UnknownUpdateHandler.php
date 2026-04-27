<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\BaleClient;

class UnknownUpdateHandler extends BaseHandler
{
    public function __construct(BaleClient $baleClient)
    {
        parent::__construct($baleClient);
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