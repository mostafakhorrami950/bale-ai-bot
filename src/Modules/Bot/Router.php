<?php

namespace Modules\Bot;

use Modules\Bot\Handlers\AccountHandler;
use Modules\Bot\Handlers\BuyCreditHandler;
use Modules\Bot\Handlers\CallbackHandler;
use Modules\Bot\Handlers\CancelHandler;
use Modules\Bot\Handlers\ImageHandler;
use Modules\Bot\Handlers\Img2ImgHandler;
use Modules\Bot\Handlers\ChatHandler;
use Modules\Bot\Handlers\MessageHandler;
use Modules\Bot\Handlers\VideoHandler;
use Modules\Bot\Handlers\StartHandler;
use Modules\Bot\Handlers\BaseHandler;
use Modules\Bot\Handlers\UnknownUpdateHandler;
use Modules\Memory\MemoryManager;
use Modules\Memory\Handlers\MemoryCommandHandler;
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

        // -------------------------------------------------------
        // PRIORITY 1: Always-respected commands (override state)
        // -------------------------------------------------------

        // 1. /start — always works, clears any stuck state
        if ($text === '/start' || str_starts_with($text, '/start')) {
            error_log("DEBUG ROUTER: -> StartHandler");
            // Clear any stuck state first
            $this->clearUserStateByBaleId($update->getUserId());
            return new StartHandler($this->baleClient);
        }

        // 2. /cancel — always works, clears state
        if ($text === '/cancel') {
            // Clear state first
            $this->clearUserStateByBaleId($update->getUserId());
            error_log("DEBUG ROUTER: -> CancelHandler");
            return new CancelHandler($this->baleClient);
        }

        // 3. "منو اصلی" — always shows main menu, clears state
        if ($text === "\xD9\x85\xD9\x86\xD9\x88 \xD8\xA7\xD8\xB5\xD9\x84\xDB\x8C") {
            $this->clearUserStateByBaleId($update->getUserId());
            error_log("DEBUG ROUTER: main menu text -> StartHandler");
            return new StartHandler($this->baleClient);
        }

        // 4. Contact messages — must work regardless
        if ($update->getContact() !== null) {
            error_log("DEBUG ROUTER: contact -> MessageHandler");
            return new MessageHandler($this->baleClient);
        }

        // -------------------------------------------------------
        // PRIORITY 1.b: Callback queries — always respected, bypass state
        // -------------------------------------------------------
        if ($update->isCallback()) {
            $data = $update->getCallbackData() ?? '';
            error_log("DEBUG ROUTER: callback=[" . $data . "]");
            $map = [
                'buy_credit' => 'BuyCreditHandler',
                'generate_image' => 'ImageHandler',
                'edit_image' => 'Img2ImgHandler',
                'account' => 'AccountHandler',
                'help' => 'MessageHandler',
                'check_membership' => 'CallbackHandler',
                'edit_photos_done' => 'Img2ImgHandler',
                'start_chat' => 'ChatHandler',
                'chat_use_default' => 'ChatHandler',
                'chat_select_model' => 'ChatHandler',
                'chat_history' => 'ChatHandler',
                'generate_video' => 'VideoHandler',
                'show_memory' => 'MemoryCommandHandler',
                'clear_memory' => 'MemoryCommandHandler',
                'toggle_memory' => 'MemoryCommandHandler',
                'add_memory' => 'MemoryCommandHandler',
                'confirm_clear_memory' => 'MemoryCommandHandler',
                'cancel_clear_memory' => 'MemoryCommandHandler',
            ];

            // Memory callbacks — special handler with different namespace and constructor
            if (in_array($data, ['show_memory', 'clear_memory', 'toggle_memory', 'add_memory', 'confirm_clear_memory', 'cancel_clear_memory'], true)) {
                $memoryManager = new MemoryManager();
                return new MemoryCommandHandler($this->baleClient, $memoryManager);
            }
            // Memory delete by ID: delete_mem_{id}
            if (str_starts_with($data, 'delete_mem_')) {
                $memoryManager = new MemoryManager();
                return new MemoryCommandHandler($this->baleClient, $memoryManager);
            }
            // Memory importance: mem_imp_{text}_{stars}
            if (str_starts_with($data, 'mem_imp_')) {
                $memoryManager = new MemoryManager();
                return new MemoryCommandHandler($this->baleClient, $memoryManager);
            }

            // Unique prefixes — each handler owns its own namespace
            if (str_starts_with($data, 'img_select_model_')) {
                return new ImageHandler($this->baleClient);
            }
            if (str_starts_with($data, 'edit_select_model_')) {
                return new Img2ImgHandler($this->baleClient);
            }
            if (str_starts_with($data, 'vid_select_model_') || str_starts_with($data, 'vid_res_') || str_starts_with($data, 'vid_ar_') || str_starts_with($data, 'vid_dur_') || str_starts_with($data, 'vid_confirm_') || str_starts_with($data, 'vid_skip_') || $data === 'vid_back_model') {
                return new VideoHandler($this->baleClient);
            }
            if (str_starts_with($data, 'chat_pick_model_') || str_starts_with($data, 'chat_resume_') || str_starts_with($data, 'chat_delete_conv_') || str_starts_with($data, 'chat_history_page_')) {
                return new ChatHandler($this->baleClient);
            }
            // plan_* callbacks — buy credit flow
            if (str_starts_with($data, 'plan_') || str_starts_with($data, 'pay_zibal_') || str_starts_with($data, 'pay_bale_')) {
                return new BuyCreditHandler($this->baleClient);
            }
            if (isset($map[$data])) {
                $class = 'Modules\\Bot\\Handlers\\' . $map[$data];
                return new $class($this->baleClient);
            }
            return new UnknownUpdateHandler($this->baleClient);
        }

        // -------------------------------------------------------
        // PRIORITY 2: State-based routing (for ongoing flows)
        // -------------------------------------------------------
        $userId = $update->getUserId();
        $state = 'idle';
        if ($userId) {
            try {
                $db = Database::getInstance();
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
            
            if ($state === 'awaiting_image_prompt' || $state === 'selecting_model_image' || $state === 'ai_processing') {
                error_log("DEBUG ROUTER: state=[" . $state . "] -> ImageHandler");
                return new ImageHandler($this->baleClient);
            }
            if ($state === 'awaiting_edit_photo' || $state === 'selecting_model_edit' || $state === 'awaiting_edit_prompt') {
                error_log("DEBUG ROUTER: state=[" . $state . "] -> Img2ImgHandler");
                return new Img2ImgHandler($this->baleClient);
            }
            // Chat states
            if (in_array($state, ['chat_active', 'chat_selecting_model', 'chat_viewing_history'], true)) {
                error_log("DEBUG ROUTER: state=[" . $state . "] -> ChatHandler");
                return new ChatHandler($this->baleClient);
            }
            // Video states
            if (in_array($state, ['awaiting_video_prompt', 'awaiting_video_first_frame', 'awaiting_video_last_frame', 'awaiting_video_reference', 'vid_processing', 'vid_polling'], true)) {
                error_log("DEBUG ROUTER: state=[" . $state . "] -> VideoHandler");
                return new VideoHandler($this->baleClient);
            }
            // Memory add text state
            if ($state === 'awaiting_memory_text') {
                error_log("DEBUG ROUTER: state=[" . $state . "] -> MemoryCommandHandler");
                $memoryManager = new MemoryManager();
                return new MemoryCommandHandler($this->baleClient, $memoryManager);
            }
        }

        // 5. Photo messages — route to ChatHandler if in chat_active state
        if ($update->hasPhoto()) {
            if ($state === 'chat_active') {
                error_log("DEBUG ROUTER: photo in chat -> ChatHandler");
                return new ChatHandler($this->baleClient);
            }
            error_log("DEBUG ROUTER: photo -> Img2ImgHandler");
            return new Img2ImgHandler($this->baleClient);
        }

        // 5b. Document messages — route to ChatHandler if in chat_active state
        if ($update->hasDocument()) {
            if ($state === 'chat_active') {
                error_log("DEBUG ROUTER: document in chat -> ChatHandler");
                return new ChatHandler($this->baleClient);
            }
            error_log("DEBUG ROUTER: document -> MessageHandler");
            return new MessageHandler($this->baleClient);
        }

        // 7. Regular message — route by text content
        if ($update->isMessage() && $text !== '') {
            // Ensure $userId is defined for memory checks
            $baleUserIdForMemory = $update->getUserId();
            
            // Memory commands (if module enabled) — only Persian button texts, no slash commands
            $memoryManager = new MemoryManager();
            if ($memoryManager->isEnabled() && in_array($text, ['🧠 حافظه من', '🗑 پاک کردن حافظه'], true)) {
                error_log("DEBUG ROUTER: memory command -> MemoryCommandHandler");
                return new MemoryCommandHandler($this->baleClient, $memoryManager);
            }
            // If user is in add_memory flow (DB state), route to MemoryCommandHandler
            if ($memoryManager->isEnabled() && $baleUserIdForMemory) {
                try {
                    $dbForMem = Database::getInstance();
                    $userRow = $dbForMem->query("SELECT id FROM users WHERE bale_user_id = ?", [$baleUserIdForMemory])->fetch();
                    if ($userRow) {
                        $memState = $dbForMem->query("SELECT state FROM bot_state WHERE user_id = ?", [(int)$userRow['id']])->fetch();
                        if ($memState && $memState['state'] === 'awaiting_memory_text') {
                            error_log("DEBUG ROUTER: memory add text (DB state) -> MemoryCommandHandler");
                            return new MemoryCommandHandler($this->baleClient, $memoryManager);
                        }
                    }
                } catch (\Throwable $e) {}
            }
            
            // Map Persian menu texts to handlers
            $menuMap = [
                "\xF0\x9F\x8E\xA8 ساخت تصویر" => 'ImageHandler',
                "\xF0\x9F\x96\xBC ویرایش عکس" => 'Img2ImgHandler',
                "\xF0\x9F\x92\xAC چت با هوش مصنوعی" => 'ChatHandler',
                "\xF0\x9F\x91\xA4 حساب کاربری" => 'AccountHandler',
                "\xF0\x9F\x8E\xAC ساخت ویدئو با هوش مصنوعی" => 'VideoHandler',
                "\xF0\x9F\x92\xB3 خرید اعتبار" => 'BuyCreditHandler',
                "\xE2\x9D\x93 راهنما" => 'MessageHandler',
            ];
            if (isset($menuMap[$text])) {
                $class = 'Modules\\Bot\\Handlers\\' . $menuMap[$text];
                error_log("DEBUG ROUTER: menu text -> " . $menuMap[$text]);
                return new $class($this->baleClient);
            }
            error_log("DEBUG ROUTER: -> MessageHandler");
            return new MessageHandler($this->baleClient);
        }

        // 8. Fallback
        error_log("DEBUG ROUTER: -> UnknownUpdateHandler (fallback)");
        return new UnknownUpdateHandler($this->baleClient);
    }

    /**
     * Clear any stuck bot state for a given Bale user ID.
     */
    private function clearUserStateByBaleId(?int $baleUserId): void
    {
        if (!$baleUserId) return;
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT id FROM users WHERE bale_user_id = ?", [$baleUserId]);
            $row = $stmt->fetch();
            if ($row) {
                $db->query("DELETE FROM bot_state WHERE user_id = ?", [(int) $row['id']]);
            }
        } catch (\Throwable $e) {
            // Silent
        }
    }
}