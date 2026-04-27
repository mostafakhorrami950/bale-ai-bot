<?php

namespace Modules\Bot\Handlers;

use Database\Database;

class CancelHandler extends BaseHandler
{
    public function handle($update): void
    {
        $chatId = $update->getChatId();
        $userId = $update->getUserId();

        if (!$chatId) return;

        // Clear any user state
        if ($userId) {
            try {
                $db = Database::getInstance();
                $stmt = $db->query("SELECT id FROM users WHERE bale_user_id = ?", [$userId]);
                $row = $stmt->fetch();
                if ($row) {
                    $db->query("DELETE FROM bot_state WHERE user_id = ?", [(int) $row['id']]);
                }
            } catch (\Throwable $e) {
                // Silent
            }
        }

        $this->baleClient->sendMessage(
            $chatId,
            "\xE2\x9C\x88 \xD8\xB9\xD9\x85\xD9\x84\xDB\x8C\xD8\xA7\xD8\xAA \xD9\x84\xD8\xBA\xD9\x88 \xD8\xB4\xD8\xAF.",
            [
                'keyboard' => [
                    [['text' => '/cancel'], ['text' => "\xD9\x85\xD9\x86\xD9\x88 \xD8\xA7\xD8\xB5\xD9\x84\xDB\x8C"]]
                ],
                'resize_keyboard' => true
            ]
        );
    }
}