<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\BaleClient;
use Modules\Bot\Models\Channel;
use Database\Database;

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
                $result = $this->baleClient->getChatMember($chId, $baleUserId);
                $status = $result['status'] ?? 'left';
                if (!in_array($status, ['member', 'creator', 'administrator'], true)) {
                    $nonMembers[] = $ch;
                }
            }

            if (!empty($nonMembers)) {
                $msg = "🔒 برای استفاده از ربات باید در کانال‌های زیر عضو شوید:\n\n";
                $keyboard = ['inline_keyboard' => []];
                foreach ($nonMembers as $ch) {
                    $title = $ch['title'] ?? 'کانال';
                    $link = $ch['invite_link'] ?? '';
                    if ($link) {
                        // Each channel as a clickable url button
                        $keyboard['inline_keyboard'][] = [
                            ['text' => "� {$title}", 'url' => $link]
                        ];
                    } else {
                        $msg .= "📢 {$title}\n";
                    }
                }
                $msg .= "✅ پس از عضویت در تمام کانال‌ها، دکمه زیر را بزنید تا مجدداً بررسی شود.";
                $keyboard['inline_keyboard'][] = [
                    ['text' => '✅ عضو شدم، بررسی کن', 'callback_data' => 'check_membership']
                ];
                $this->baleClient->sendMessage($chatId, $msg, $keyboard);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // If API fails, allow through (fail open)
            error_log("checkMembership error: " . $e->getMessage());
            return true;
        }
    }
}