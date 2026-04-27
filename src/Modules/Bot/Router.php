<?php

namespace Modules\Bot;

use Modules\Bot\Handlers\AccountHandler;
use Modules\Bot\Handlers\BuyCreditHandler;
use Modules\Bot\Handlers\CallbackHandler;
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
                // bot_state.user_id = internal users.id, so JOIN on bale_user_id
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

        // 2. Contact messages
        if ($update->getContact() !== null) {
            error_log("DEBUG ROUTER: contact -> MessageHandler");
            return new MessageHandler($this->baleClient);
        }

        // 3. Menu text buttons
        $menuRoutes = [
            '🎨 ساخت تصویر' => 'ImageHandler',
            '🖼 ویرایش عکس' => 'Img2ImgHandler',
            '💳 شارژ اعتبار' => 'BuyCreditHandler',
            '👤 حساب من' => 'AccountHandler',
            '❓ راهنما' => 'ImageHandler',
        ];
        if (array_key_exists($text, $menuRoutes)) {
            $class = 'Modules\\Bot\\Handlers\\' . $menuRoutes[$text];
            error_log("DEBUG ROUTER: menu text=[" . $text . "] -> " . $menuRoutes[$text]);
            return new $class($this->baleClient);
        }

        // 4. Photo messages
        if ($update->hasPhoto()) {
            error_log("DEBUG ROUTER: photo -> Img2ImgHandler");
            return new Img2ImgHandler($this->baleClient);
        }

        // 5. Callback queries
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
                'check_membership' => 'CallbackHandler',
            ];
            if (isset($map[$data])) {
                $class = 'Modules\\Bot\\Handlers\\' . $map[$data];
                return new $class($this->baleClient);
            }
            return new UnknownUpdateHandler($this->baleClient);
        }

        // 6. Regular message
        if ($update->isMessage() && $text !== '') {
            error_log("DEBUG ROUTER: -> MessageHandler");
            return new MessageHandler($this->baleClient);
        }

        // 7. Fallback
        error_log("DEBUG ROUTER: -> UnknownUpdateHandler (fallback)");
        return new UnknownUpdateHandler($this->baleClient);
    }
}