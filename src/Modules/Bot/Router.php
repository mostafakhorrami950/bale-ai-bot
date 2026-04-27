<?php

namespace Modules\Bot;

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

    private const CALLBACK_MAP = [
        'buy_credit'     => BuyCreditHandler::class,
        'generate_image' => ImageHandler::class,
        'edit_image'     => Img2ImgHandler::class,
        // Plan selection callbacks (prefix match)
        'plan_'          => BuyCreditHandler::class,
    ];

    private const STATE_HANDLER_MAP = [
        'awaiting_image_prompt' => ImageHandler::class,
        'awaiting_edit_photo'   => Img2ImgHandler::class,
        'awaiting_edit_prompt'  => Img2ImgHandler::class,
    ];

    /**
     * Resolve the Update to the appropriate handler instance.
     */
    public function resolve($update)
    {
        $text = $update->getText() ?? '';
        error_log("DEBUG ROUTER: text=[" . $text . "]");

        // 1. Commands
        if ($text === '/start' || str_starts_with($text, '/start')) {
            error_log("DEBUG ROUTER: -> StartHandler");
            return new StartHandler($this->baleClient);
        }

        // 2. Contact messages (phone sharing)
        if ($update->getContact() !== null) {
            error_log("DEBUG ROUTER: contact=[" . json_encode($update->getContact()) . "] -> MessageHandler");
            return new MessageHandler($this->baleClient);
        }

        // 3. Menu text buttons — MOVED BEFORE callback check
        $menuRoutes = [
            '🎨 ساخت تصویر' => 'ImageHandler',
            '🖼 ویرایش عکس' => 'Img2ImgHandler',
            '💳 شارژ اعتبار' => 'BuyCreditHandler',
            '👤 حساب من' => 'ImageHandler',
            '❓ راهنما' => 'ImageHandler',
        ];

        if (array_key_exists($text, $menuRoutes)) {
            $class = 'Modules\\Bot\\Handlers\\' . $menuRoutes[$text];
            error_log("DEBUG ROUTER: menu text=[" . $text . "] -> " . $menuRoutes[$text]);
            return new $class($this->baleClient);
        }

        // 4. Callback queries
        if ($update->isCallback()) {
            $data = $update->getCallbackData() ?? '';
            error_log("DEBUG ROUTER: callback=[" . $data . "]");
            
            $map = [
                'buy_credit' => 'BuyCreditHandler',
                'generate_image' => 'ImageHandler',
                'edit_image' => 'Img2ImgHandler',
                'plan_basic' => 'BuyCreditHandler',
                'plan_standard' => 'BuyCreditHandler',
                'plan_premium' => 'BuyCreditHandler',
                'check_membership' => 'CallbackHandler',
            ];
            
            if (isset($map[$data])) {
                $class = 'Modules\\Bot\\Handlers\\' . $map[$data];
                return new $class($this->baleClient);
            }
            
            return new UnknownUpdateHandler($this->baleClient);
        }

        // 5. Regular message
        if ($update->isMessage() && $text !== '') {
            error_log("DEBUG ROUTER: -> MessageHandler");
            return new MessageHandler($this->baleClient);
        }

        // 6. Fallback
        error_log("DEBUG ROUTER: -> UnknownUpdateHandler (fallback)");
        return new UnknownUpdateHandler($this->baleClient);
    }

    /**
     * Resolve callback data to handler instance.
     */
    private function resolveCallback(Update $update): BaseHandler
    {
        $callbackData = $update->getCallbackData() ?? '';

        // Exact matches first
        foreach (self::CALLBACK_MAP as $key => $handlerClass) {
            if (str_ends_with($key, '_')) continue;
            if ($callbackData === $key) {
                return new $handlerClass($this->baleClient);
            }
        }

        // Prefix matches (e.g. "plan_" matches "plan_basic")
        foreach (self::CALLBACK_MAP as $key => $handlerClass) {
            if (str_ends_with($key, '_') && str_starts_with($callbackData, $key)) {
                return new $handlerClass($this->baleClient);
            }
        }

        return new UnknownUpdateHandler($this->baleClient);
    }

    private function getUserState(?int $userId): ?string
    {
        if ($userId === null) return null;
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT state FROM bot_state WHERE user_id = ?", [$userId]);
            $row = $stmt->fetch();
            return $row['state'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}