<?php

namespace Modules\Bot;

use Modules\Bot\Handlers\BuyCreditHandler;
use Modules\Bot\Handlers\CallbackHandler;
use Modules\Bot\Handlers\ImageHandler;
use Modules\Bot\Handlers\Img2ImgHandler;
use Modules\Bot\Handlers\MessageHandler;
use Modules\Bot\Handlers\StartHandler;
use Modules\Bot\Handlers\UnknownUpdateHandler;
use Database\Database;

class Router
{
    /**
     * Map callback_data values to their handler classes.
     */
    private const CALLBACK_MAP = [
        'buy_credit'     => 'BuyCreditHandler',
        'generate_image' => 'ImageHandler',
        'edit_image'     => 'Img2ImgHandler',
        // Plan selection callbacks (prefix match) → BuyCreditHandler
        'plan_'          => 'BuyCreditHandler',
    ];

    /**
     * Map bot_state values to the correct handler when user sends a text message.
     */
    private const STATE_HANDLER_MAP = [
        'awaiting_image_prompt' => 'ImageHandler',
        'awaiting_edit_photo'   => 'Img2ImgHandler',
        'awaiting_edit_prompt'  => 'Img2ImgHandler',
    ];

    /**
     * Resolve the Update to the appropriate handler class name.
     * I5: Check if callback is already answered before routing (via webhook early answer).
     */
    public function resolve(Update $update): string
    {
        // 1. Handle callback queries via CALLBACK_MAP
        // (Callback is already answered early in webhook.php to remove loading state)
        if ($update->isCallback()) {
            return $this->resolveCallback($update);
        }

        // 2. Exact command matching — guard against null text
        $rawText = $update->getText();
        $text = is_string($rawText) ? trim($rawText) : '';
        $normalizedText = mb_strtolower($text);

        if ($normalizedText === '/start') {
            return StartHandler::class;
        }

        // 3. State-based routing for messages (text or photo)
        if ($update->isMessage()) {
            $state = $this->getUserState($update->getUserId());
            if ($state !== null && isset(self::STATE_HANDLER_MAP[$state])) {
                $handlerShort = self::STATE_HANDLER_MAP[$state];
                return "Modules\\Bot\\Handlers\\{$handlerShort}";
            }
        }

        // 4. Main menu / known button mapping
        $buttonMap = [
            '🎨 ساخت تصویر' => 'ImageHandler',
            '👤 حساب من'    => 'MessageHandler',
            '💳 شارژ اعتبار' => 'BuyCreditHandler',
            '❓ راهنما'     => 'MessageHandler',
            '🖼️ ویرایش عکس' => 'Img2ImgHandler',
        ];

        if (isset($buttonMap[$text])) {
            $handlerShort = $buttonMap[$text];
            return "Modules\\Bot\\Handlers\\{$handlerShort}";
        }

        // 5. Contact messages (registration flow)
        if ($update->getContact() !== null) {
            return MessageHandler::class;
        }

        // 6. Fallback for unrecognized text
        return MessageHandler::class;
    }

    /**
     * Resolve callback data to handler.
     */
    private function resolveCallback(Update $update): string
    {
        $callbackData = $update->getCallbackData();

        // Check exact matches first
        foreach (self::CALLBACK_MAP as $key => $handlerShort) {
            // Skip prefix-only entries in exact match loop
            if (substr($key, -1) === '_') continue;
            if ($callbackData === $key) {
                return "Modules\\Bot\\Handlers\\{$handlerShort}";
            }
        }

        // Check prefix matches (e.g. "plan_" matches "plan_basic")
        foreach (self::CALLBACK_MAP as $key => $handlerShort) {
            if (substr($key, -1) === '_' && strpos($callbackData, $key) === 0) {
                return "Modules\\Bot\\Handlers\\{$handlerShort}";
            }
        }

        // Fallback for unknown callbacks
        return UnknownUpdateHandler::class;
    }

    /**
     * Get user state from bot_state table.
     */
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