<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\BaleClient;

abstract class BaseHandler
{
    protected BaleClient $baleClient;

    public function __construct(BaleClient $baleClient)
    {
        $this->baleClient = $baleClient;
    }

    abstract public function handle($update): void;
}