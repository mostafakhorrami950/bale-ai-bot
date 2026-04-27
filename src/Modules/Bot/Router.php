<?php

namespace Modules\Bot;

use Modules\Bot\Handlers\AccountHandler;
use Modules\Bot\Handlers\BuyCreditHandler;
use Modules\Bot\Handlers\CallbackHandler;
use Modules\Bot\Handlers\CancelHandler;
use Modules\Bot\Handlers\ImageHandler;
use Modules\Bot\Handlers\Img2ImgHandler;
use Modules\Bot\Handlers\MessageHandler;
use Modules\Bot\Handlers\StartHandler;
use Modules\Bot\Handlers\BaseHandler;
use Modules\Bot\Handlers\UnknownUpdateHandler;
use Database\Database;

class Router
{
    private $baleClient;

    public function __construct()
    {
        $this->baleClient = new BaleClient();
    }

    public function resolve($update)
    {
        $text = $update->getText() ?? '';
        error_log("DEBUG ROUTER: text=[" . $text . "]");

        // 0. State-based routing — CHECK FIRST before anything else
        $userId = $update->getUserId();
        $state = 'idle';
        if ($userId) {
            try {
                $db = Database::getInstance();
                $stmt = $db->query(
                    "SELECT bs.state FROM bot_state bs 
                     JOIN users u ON bs.user_id = u.id 
                     WHERE u.bale_user_id = ?",
                    [$userId]
                );
                $row = $stmt->fetch();
                $state = $row['state'] ?? 'idle';
            } catch (\Throwable $e) {
                $state = 'idle';
            }
            
            if ($state === 'awaiting_image_prompt' || $state === 'awaiting_edit_prompt') {
                error_log("DEBUG ROUTER: state=[" . $state . "] -> ImageHandler");
                return new ImageHandler($this->baleClient);
            }
            if ($state === 'awaiting_edit_photo') {
                error_log("DEBUG ROUTER: state=[" . $state . "] -> Img2ImgHandler");
                return new Img2ImgHandler($this->baleClient);
            }
        }

        // 1. Commands
        if ($text === '/start' || str_starts_with($text, '/start')) {
            error_log("DEBUG ROUTER: -> StartHandler");
            return new StartHandler($this->baleClient);
        }

        // 2. /cancel — clear state and cancel
        if ($text === '/cancel') {
            error_log("DEBUG ROUTER: -> CancelHandler");
            return new CancelHandler($this->baleClient);
        }

        // 3. Contact messages
        if ($update->getContact() !== null) {
            error_log("DEBUG ROUTER: contact -> MessageHandler");
            return new MessageHandler($this->baleClient);
        }

        // 4. "منو اصلی" text button — show main menu
        if ($text === "\xD9\x85\xD9\x86\xD9\x88 \xD8\xA7\xD8\xB5\xD9\x84\xDB\x8C") {
            error_log("DEBUG ROUTER: main menu text -> StartHandler");
            return new StartHandler($this->baleClient);
        }

        // 5. Photo messages
        if ($update->hasPhoto()) {
            error_log("DEBUG ROUTER: photo -> Img2ImgHandler");
            return new Img2ImgHandler($this->baleClient);
        }

        // 6. Callback queries
        if ($update->isCallback()) {
            $data = $update->getCallbackData() ?? '';
            error_log("DEBUG ROUTER: callback=[" . $data . "]");
            $map = [
                'buy_credit' => 'BuyCreditHandler',
                'plan_basic' => 'BuyCreditHandler',
                'plan_standard' => 'BuyCreditHandler',
                'plan_premium' => 'BuyCreditHandler',
                'generate_image' => 'ImageHandler',
                'edit_image' => 'Img2ImgHandler',
                'account' => 'AccountHandler',
                'help' => 'ImageHandler',
                'check_membership' => 'CallbackHandler',
            ];
            if (isset($map[$data])) {
                $class = 'Modules\\Bot\\Handlers\\' . $map[$data];
                return new $class($this->baleClient);
            }
            return new UnknownUpdateHandler($this->baleClient);
        }

        // 7. Regular message
        if ($update->isMessage() && $text !== '') {
            error_log("DEBUG ROUTER: -> MessageHandler");
            return new MessageHandler($this->baleClient);
        }

        // 8. Fallback
        error_log("DEBUG ROUTER: -> UnknownUpdateHandler (fallback)");
        return new UnknownUpdateHandler($this->baleClient);
    }
}