<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\Models\User;
use Database\Database;
use Core\BotTextService;

class StartHandler extends BaseHandler
{
    /**
     * Handle the /start command, including deep link payloads.
     */
    public function handle($update): void
    {
        $chatId = $update->getChatId();
        $userId = $update->getUserId();

        error_log("DEBUG: StartHandler::handle() CALLED. Chat ID: " . ($chatId ?? 'none'));

        if (!$chatId) return;

        try {
            // ─── Extract deep link payload ───
            $deepLinkPayload = $update->getDeepLinkPayload();
            $db = Database::getInstance();
            $campaignRow = null;
            $entryId = null;

            if ($deepLinkPayload) {
                error_log("DEBUG: Deep link payload detected: " . $deepLinkPayload);

                // Find campaign
                $stmt = $db->query("SELECT id, payload, title, welcome_text FROM deep_link_campaigns WHERE payload = ? AND is_active = 1", [$deepLinkPayload]);
                $campaignRow = $stmt->fetch();

                // Log entry
                $entryStmt = $db->prepare(
                    "INSERT INTO deep_link_entries (campaign_id, payload, bale_user_id, first_name, username) VALUES (?, ?, ?, ?, ?)"
                );
                $entryStmt->execute([
                    $campaignRow ? (int)$campaignRow['id'] : null,
                    $deepLinkPayload,
                    $userId,
                    $update->getFirstName() ?? '',
                    $update->getUsername() ?? ''
                ]);
                $entryId = (int)$db->lastInsertId();
                error_log("DEBUG: Deep link entry logged. entry_id={$entryId}");
            }

            // ─── Save/update user profile ───
            $firstName = $update->getFirstName();
            $username = $update->getUsername();
            if ($firstName || $username) {
                try {
                    $user = User::findByBaleId($userId);
                    if ($user) {
                        $db->query(
                            "INSERT INTO user_profiles (user_id, first_name, username)
                             VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE first_name = COALESCE(NULLIF(?, ''), first_name), username = COALESCE(NULLIF(?, ''), username)",
                            [$user['id'], $firstName ?? '', $username ?? '', $firstName ?? '', $username ?? '']
                        );

                        // Link deep link entry to registered user if user exists
                        if ($entryId) {
                            $db->query(
                                "UPDATE deep_link_entries SET registered_user_id = ? WHERE id = ?",
                                [$user['id'], $entryId]
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("StartHandler: profile save error: " . $e->getMessage());
                }
            }

            $isRegistered = User::isRegistered($userId);

            // ─── Determine welcome text ───
            $welcomeText = null;
            if (!$isRegistered) {
                if ($campaignRow && !empty($campaignRow['welcome_text'])) {
                    $welcomeText = $campaignRow['welcome_text'];
                } else {
                    $welcomeText = BotTextService::get('welcome_unregistered');
                    if ($deepLinkPayload) {
                        $welcomeText = str_replace('{payload}', $deepLinkPayload, BotTextService::get('welcome_deeplink_unregistered', ['payload' => $deepLinkPayload]));
                    }
                }
            } else {
                if ($campaignRow && !empty($campaignRow['welcome_text'])) {
                    $welcomeText = $campaignRow['welcome_text'];
                } else {
                    $welcomeText = BotTextService::get('welcome_registered');
                }
            }

            if (!$isRegistered) {
                $keyboard = [
                    'keyboard' => [
                        [['text' => "\xF0\x9F\x93\xB1 \xD8\xA7\xD8\xB1\xD8\xB3\xD8\xA7\xD9\x84 \xD8\xB4\xD9\x85\xD8\xA7\xD8\xB1\xD9\x87", 'request_contact' => true]]
                    ],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true
                ];

                $this->baleClient->sendMessage($chatId, $welcomeText, $keyboard);
            } else {
                // Show campaign welcome even for registered users (once)
                if ($campaignRow && !empty($campaignRow['welcome_text'])) {
                    $this->baleClient->sendMessage($chatId, $welcomeText);
                }

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
