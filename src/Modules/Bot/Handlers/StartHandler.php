<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\Models\User;
use Database\Database;
use Core\BotTextService;

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
                    BotTextService::get('welcome_unregistered'),
                    $keyboard
                );
            } else {
                // Check membership before showing main menu
                if (!$this->checkMembership($userId, $chatId)) return;
                $this->showMainMenu($chatId);
            }
        } catch (\Exception $e) {
            error_log("StartHandler ERROR: " . $e->getMessage());
            $this->baleClient->sendMessage($chatId, BotTextService::get('error_general'));
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
            BotTextService::get('main_menu_prompt'),
            MessageHandler::getMainMenuKeyboard()
        );
    }

}
