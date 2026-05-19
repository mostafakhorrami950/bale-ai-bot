<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\BaleClient;
use Modules\Bot\Models\Channel;
use Database\Database;
use Core\BotTextService;

abstract class BaseHandler
{
    protected BaleClient $baleClient;

    public function __construct(BaleClient $baleClient)
    {
        $this->baleClient = $baleClient;
    }

    abstract public function handle($update): void;

    /**
     * Check if user is a member of all required channels.
     * If not, send a message with clickable channel invite buttons and return false.
     * Returns true if the user can proceed.
     */
    /**
     * Store the membership message ID so it can be deleted on confirmation.
     */
    private function storeMembershipMessageId(int $chatId, int $messageId): void
    {
        try {
            $db = Database::getInstance();
            $db->query("INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?",
                ['membership_msg_' . $chatId, (string)$messageId, (string)$messageId]
            );
        } catch (\Throwable $e) {}
    }

    /**
     * Get and delete the stored membership message ID.
     */
    protected function getAndDeleteMembershipMessageId(int $chatId): ?int
    {
        try {
            $db = Database::getInstance();
            $row = $db->query("SELECT value FROM settings WHERE key_name = ?", ['membership_msg_' . $chatId])->fetch();
            if ($row) {
                $db->query("DELETE FROM settings WHERE key_name = ?", ['membership_msg_' . $chatId]);
                return (int)$row['value'];
            }
        } catch (\Throwable $e) {}
        return null;
    }

    /**
     * Check if user is a member of all required channels.
     * If not, send a message with clickable channel invite buttons and return false.
     * Returns true if the user can proceed.
     *
     * FIX: Added per-channel error handling so that a failure on one channel
     * does not abort the entire check. Added detailed logging.
     */
    protected function checkMembership(int $baleUserId, int $chatId): bool
    {
        try {
            $channels = Channel::getAllRequired();
            if (empty($channels)) {
                return true; // no required channels
            }

            $nonMembers = [];
            foreach ($channels as $ch) {
                $chId = $ch['channel_id'];
                $title = $ch['title'] ?? $chId;

                try {
                    $result = $this->baleClient->getChatMember($chId, $baleUserId);

                    // If result is null, API returned ok:false or Connection Error
                    if ($result === null) {
                        // Check if it was a connection error or just "not member/not admin"
                        $lastError = $this->baleClient->getLastError();
                        if (str_contains($lastError, 'timed out') || str_contains($lastError, 'refused')) {
                            // If Bale API is unreachable, we MUST inform user that verification is currently impossible
                            $this->baleClient->sendMessage($chatId, "⚠️ در حال حاضر امکان برقراری ارتباط با سرورهای بله برای تأیید عضویت وجود ندارد. لطفاً چند لحظه دیگر تلاش کنید.");
                            return false;
                        }
                        // Other API errors (bot not admin): assume NOT member for safety, or allow if you prefer
                        $nonMembers[] = $ch;
                        continue;
                    }

                    $status = $result['status'] ?? 'left';
                } catch (\Throwable $e) {
                    $nonMembers[] = $ch;
                    continue;
                }

                if (!in_array($status, ['member', 'creator', 'administrator'], true)) {
                    error_log("checkMembership: User $baleUserId NOT a member of channel $title (status=$status)");
                    $nonMembers[] = $ch;
                } else {
                    error_log("checkMembership: User $baleUserId IS a member of channel $title (status=$status)");
                }
            }

            if (!empty($nonMembers)) {
                $msg = BotTextService::get('membership_required');
                $keyboard = ['inline_keyboard' => []];
                foreach ($nonMembers as $ch) {
                    $title = $ch['title'] ?? 'کانال';
                    $link = $ch['invite_link'] ?? '';
                    if ($link) {
                        // Each channel as a clickable url button
                        $keyboard['inline_keyboard'][] = [
                            ['text' => "📢 {$title}", 'url' => $link]
                        ];
                    } else {
                        $msg .= "📢 {$title}\n";
                    }
                }
                $msg .= BotTextService::get('membership_check_prompt');
                $keyboard['inline_keyboard'][] = [
                    ['text' => BotTextService::get('membership_check_button'), 'callback_data' => 'check_membership']
                ];
                // Store the message_id so we can delete it later on confirmation
                $msgId = $this->baleClient->sendMessage($chatId, $msg, $keyboard);
                if ($msgId !== false) {
                    $this->storeMembershipMessageId($chatId, $msgId);
                }
                error_log("checkMembership: Blocked user $baleUserId — " . count($nonMembers) . " channel(s) not joined");
                return false;
            }

            error_log("checkMembership: User $baleUserId passed all " . count($channels) . " channel checks");
            return true;
        } catch (\Throwable $e) {
            error_log("checkMembership FATAL error: " . $e->getMessage());
            return false; // Fail closed for security
        }
    }
}