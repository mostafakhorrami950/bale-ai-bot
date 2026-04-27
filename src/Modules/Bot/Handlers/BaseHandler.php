<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\BaleClient;
use Modules\Bot\Update;
use Modules\Bot\Models\User;

abstract class BaseHandler
{
    protected $update;
    protected $bale;
    protected $userModel;

    public function __construct(Update $update)
    {
        $this->update = $update;
        $this->bale = new BaleClient();
        $this->userModel = new User();
    }

    abstract public function handle($update): void;

    protected function sendMessage(string $text, ?array $keyboard = null)
    {
        $chatId = $this->update->getChatId();
        if (!$chatId) return false;
        return $this->bale->sendMessage($chatId, $text, $keyboard);
    }
}