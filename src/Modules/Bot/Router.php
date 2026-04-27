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
    public function resolve(Update $update): BaseHandler
    {
        // 1. Handle callback queries
        if ($update->isCallback()) {
            return $this->resolveCallback($update);
        }

        // 2. Guard against null text
        $rawText = $update->getText();
        $text = is_string($rawText) ? trim($rawText) : '';
        $normalizedText = mb_strtolower($text);
        error_log("DEBUG ROUTER: isCallback=" . ($update->isCallback() ? 'true' : 'false') . " isMessage=" . ($update->isMessage() ? 'true' : 'false') . " rawText='" . $rawText . "' normalized='" . $normalizedText . "'");

        if ($normalizedText === '/start') {
            return new StartHandler($this->baleClient);
        }

        // 3. State-based routing
        if ($update->isMessage()) {
            $state = $this->getUserState($update->getUserId());
            if ($state !== null && isset(self::STATE_HANDLER_MAP[$state])) {
                $handlerClass = self::STATE_HANDLER_MAP[$state];
                return new $handlerClass($this->baleClient);
            }
        }

        // 4. Button mapping
        $buttonMap = [
            '🎨 ساخت تصویر' => ImageHandler::class,
            '👤 حساب من'    => MessageHandler::class,
            '💳 شارژ اعتبار' => BuyCreditHandler::class,
            '❓ راهنما'     => MessageHandler::class,
            '🖼️ ویرایش عکس' => Img2ImgHandler::class,
        ];

        if (isset($buttonMap[$text])) {
            $handlerClass = $buttonMap[$text];
            return new $handlerClass($this->baleClient);
        }

        // 5. Contact messages
        if ($update->getContact() !== null) {
            return new MessageHandler($this->baleClient);
        }

        // 6. Fallback
        return new MessageHandler($this->baleClient);
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