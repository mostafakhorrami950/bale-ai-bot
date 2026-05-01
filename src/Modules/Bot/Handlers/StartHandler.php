<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\Models\User;
use Database\Database;

class StartHandler extends BaseHandler
{
    public function handle($update): void
    {
        $chatId = $update->getChatId();
        $userId = $update->getUserId();

        error_log("DEBUG: StartHandler::handle() CALLED. Chat ID: " . ($chatId ?? 'none'));

        if (!$chatId) return;

        try {
            // Save/update user profile info (first_name, username) on every /start
            $firstName = $update->getFirstName();
            $username = $update->getUsername();
            if ($firstName || $username) {
                try {
                    $db = Database::getInstance();
                    $user = User::findByBaleId($userId);
                    if ($user) {
                        $db->query(
                            "INSERT INTO user_profiles (user_id, first_name, username)
                             VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE first_name = COALESCE(NULLIF(?, ''), first_name), username = COALESCE(NULLIF(?, ''), username)",
                            [$user['id'], $firstName ?? '', $username ?? '', $firstName ?? '', $username ?? '']
                        );
                    }
                } catch (\Throwable $e) {
                    error_log("StartHandler: profile save error: " . $e->getMessage());
                }
            }

            $isRegistered = User::isRegistered($userId);

            if (!$isRegistered) {
                $keyboard = [
                    'keyboard' => [
                        [['text' => "\xF0\x9F\x93\xB1 \xD8\xA7\xD8\xB1\xD8\xB3\xD8\xA7\xD9\x84 \xD8\xB4\xD9\x85\xD8\xA7\xD8\xB1\xD9\x87", 'request_contact' => true]]
                    ],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true
                ];

                $this->baleClient->sendMessage(
                    $chatId,
                    "\xD8\xB3\xD9\x84\xD8\xA7\xD9\x85! \xF0\x9F\x91\x8B\n\n\xD8\xA8\xD9\x87 \xD8\xB1\xD8\xA8\xD8\xA7\xD8\xAA \xD9\x87\xD9\x88\xD8\xB4 \xD9\x85\xD8\xB5\xD9\x86\xD9\x88\xD8\xB9\xDB\x8C \xD8\xAE\xD9\x88\xD8\xB4 \xD8\xA2\xD9\x85\xD8\xAF\xDB\x8C\xD8\xAF.\n\xD8\xA8\xD8\xB1\xD8\xA7\xDB\x8C \xD8\xA7\xD8\xB3\xD8\xAA\xD9\x81\xD8\xA7\xD8\xAF\xD9\x87 \xD8\xA7\xD8\xB2 \xD8\xAE\xD8\xAF\xD9\x85\xD8\xA7\xD8\xAA\xD8\x8C \xD9\x84\xD8\xB7\xD9\x81\xD8\xA7\xD9\x8B \xD8\xB4\xD9\x85\xD8\xA7\xD8\xB1\xD9\x87 \xD8\xAE\xD9\x88\xD8\xAF \xD8\xB1\xD8\xA7 \xD8\xAA\xD8\xA3\xDB\x8C\xDB\x8C\xD8\xAF \xD9\x83\xD9\x86\xDB\x8C\xD8\xAF.",
                    $keyboard
                );
            } else {
                // Check membership before showing main menu
                if (!$this->checkMembership($userId, $chatId)) return;
                $this->showMainMenu($chatId);
            }
        } catch (\Exception $e) {
            error_log("StartHandler ERROR: " . $e->getMessage());
            $this->baleClient->sendMessage($chatId, "\xD9\x85\xD8\xAA\xD8\xA3\xD8\xB3\xD9\x81\xD8\xA7\xD9\x86\xD9\x87 \xD9\x85\xD8\xB4\xDA\xA9\xD9\x84\xDB\x8C \xD9\xBE\xDB\x8C\xD8\xB4 \xD8\xA2\xD9\x85\xD8\xAF. \xD9\x84\xD8\xB7\xD9\x81\xD8\xA7\xD9\x8B \xD8\xAF\xD9\x88\xD8\xA8\xD8\xA7\xD8\xB1\xD9\x87 \xD8\xAA\xD9\x84\xD8\xA7\xD8\xB4 \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF.");
        }
    }

    /**
     * Show main menu with persistent reply keyboard and inline action buttons.
     */
    public function showMainMenu(int $chatId): void
    {
        // Send the unified 6-button keyboard (same as MessageHandler)
        $this->baleClient->sendMessage(
            $chatId,
            "\xF0\x9F\xA4\x96 \xD8\xAE\xD9\x88\xD8\xB4 \xD8\xA2\xD9\x85\xD8\xAF\xDB\x8C\xD8\xAF! \xD9\x84\xD8\xB7\xD9\x81\xD8\xA7\xD9\x8B \xD8\xA7\xD8\xB2 \xD9\x85\xD9\x86\xD9\x88\xDB\x8C \xD8\xB2\xDB\x8C\xD8\xB1 \xDA\xAF\xD8\xB2\xDB\x8C\xD9\x86\xD9\x87 \xD9\x85\xD9\x88\xD8\xB1\xD8\xAF \xD9\x86\xD8\xB8\xD8\xB1 \xD8\xB1\xD8\xA7 \xD8\xA7\xD9\x86\xD8\xAA\xD8\xAE\xD8\xA7\xD8\xA8 \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF:",
            MessageHandler::getMainMenuKeyboard()
        );
    }

    /**
     * Get the Bale user ID from the update for membership checking.
     */
    public function getUserId(): ?int
    {
        return null; // placeholder, actual user ID is passed to handle()
    }
}