<?php

namespace Modules\Bot\Handlers;

use Modules\AI\AIService;
use Modules\AI\ChatService;
use Modules\Bot\CreditService;
use Modules\Memory\MemoryManager;
use Modules\Memory\Hooks as MemoryHooks;
use Database\Database;
use Database\Logger;

class ChatHandler extends BaseHandler
{
    private string $tempDir;

    public function __construct($baleClient)
    {
        parent::__construct($baleClient);
        $this->tempDir = BASE_PATH . '/uploads/tmp/';
        if (!is_dir($this->tempDir)) {
            @mkdir($this->tempDir, 0755, true);
        }
    }

    public function handle($update): void
    {
        try {
            $chatId = $update->getChatId();
            $userId = $update->getUserId();
            $text = $update->getText();
            $callbackData = $update->getCallbackData();

            $state = $this->getUserState($userId);

            // ─── PRIORITY COMMANDS ───
            if ($text === '/exit' || $text === '🚪 خروج') {
                $this->exitChat($chatId, $userId);
                return;
            }

            // ─── ENTRY ───
            if ($callbackData === 'start_chat' || $text === '💬 گفتگوی هوش') {
                $this->showChatMenu($chatId, $userId);
                return;
            }

            // ─── CALLBACK HANDLING ───
            if ($update->isCallback() && is_string($callbackData)) {
                if ($callbackData === 'chat_use_default') {
                    if (!$this->checkMembership($userId, $chatId)) return;
                    $this->startWithDefaultModel($chatId, $userId);
                    return;
                }
                if ($callbackData === 'chat_select_model') {
                    if (!$this->checkMembership($userId, $chatId)) return;
                    $this->showChatModelList($chatId, $userId);
                    return;
                }
                if ($callbackData === 'chat_history') {
                    if (!$this->checkMembership($userId, $chatId)) return;
                    $this->showConversationHistory($chatId, $userId);
                    return;
                }
                if (str_starts_with($callbackData, 'chat_pick_model_')) {
                    if (!$this->checkMembership($userId, $chatId)) return;
                    $modelId = (int) str_replace('chat_pick_model_', '', $callbackData);
                    $this->startChatWithModel($chatId, $userId, $modelId);
                    return;
                }
                if (str_starts_with($callbackData, 'chat_resume_')) {
                    if (!$this->checkMembership($userId, $chatId)) return;
                    $convId = (int) str_replace('chat_resume_', '', $callbackData);
                    $this->resumeConversation($chatId, $userId, $convId);
                    return;
                }
                if (str_starts_with($callbackData, 'chat_delete_conv_')) {
                    $convId = (int) str_replace('chat_delete_conv_', '', $callbackData);
                    $this->deleteConversation($chatId, $userId, $convId);
                    return;
                }
                if (str_starts_with($callbackData, 'chat_history_page_')) {
                    $page = (int) str_replace('chat_history_page_', '', $callbackData);
                    $this->showConversationHistory($chatId, $userId, $page);
                    return;
                }
            }

            // ─── STATE: viewing history → re-show (safety) ───
            if ($state === 'chat_viewing_history') {
                $this->showConversationHistory($chatId, $userId);
                return;
            }

            // ─── STATE: in active conversation ───
            if ($state === 'chat_active') {
                if (!$this->checkMembership($userId, $chatId)) return;
                // File upload (photo or document)
                if ($update->hasPhoto()) {
                    $this->handlePhotoInChat($chatId, $userId, $update, $text);
                    return;
                }
                if ($update->hasDocument()) {
                    $this->handleDocumentInChat($chatId, $userId, $update, $text);
                    return;
                }
                if (!empty(trim($text ?? ''))) {
                    $this->processChatMessage($chatId, $userId, trim($text), null, null);
                    return;
                }
                $this->baleClient->sendMessage($chatId, "📝 لطفاً پیام خود را بنویسید یا عکس/فایل ارسال کنید.\n/exit برای خروج.");
                return;
            }

            // ─── STATE: selecting model → re-show list ───
            if ($state === 'chat_selecting_model') {
                $this->showChatModelList($chatId, $userId);
                return;
            }

            // ─── FALLBACK ───
            $this->baleClient->sendMessage($chatId, "🤖 لطفاً از منوی زیر انتخاب کنید:", $this->getChatEntryKeyboard());

        } catch (\Throwable $e) {
            error_log("ChatHandler FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            if (isset($chatId)) {
                $this->baleClient->sendMessage($chatId, "⚠️ خطایی رخ داد. مجدداً تلاش کنید.");
            }
        }
    }

    // ─────────────────────────────────────────────
    //   ENTRY POINTS
    // ─────────────────────────────────────────────

    private function showChatMenu(int $chatId, int $userId): void
    {
        $this->baleClient->sendMessage($chatId,
            "💬 گفتگوی هوش مصنوعی\n\n"
          . "آیا می‌خواهید از مدل پیش‌فرض استفاده کنید یا مدل دیگری انتخاب نمایید؟\n\n"
          . "📌 نکات:\n"
          . "• هزینه بر اساس تعداد کاراکتر محاسبه می‌شود\n"
          . "• می‌توانید عکس و فایل نیز ارسال کنید\n"
          . "• با /exit از مکالمه خارج شوید",
            $this->getChatEntryKeyboard()
        );
    }

    private function startWithDefaultModel(int $chatId, int $userId): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) {
            $this->baleClient->sendMessage($chatId, "❌ کاربر یافت نشد.");
            return;
        }

        $aiService = new AIService();
        $model = $aiService->getFirstActiveTextModel();

        if (!$model) {
            $msg = '❌ هیچ مدل متنی فعالی یافت نشد. لطفاً ابتدا یک مدل اضافه کنید.';
            $this->baleClient->sendMessage($chatId, $msg);
            return;
        }

        $this->createAndStartConversation($chatId, $internalId, $userId, $model);
    }

    private function showChatModelList(int $chatId, int $userId): void
    {
        $internalId = $this->resolveUserId($userId);
        try {
            $db = Database::getInstance();
            $models = $db->query("SELECT id, name, display_name, description, provider, cost_per_input_char, cost_per_output_char, free_model FROM ai_text_models WHERE is_active = 1 ORDER BY sort_order ASC, free_model DESC, id ASC")->fetchAll();

            if (empty($models)) {
                $this->baleClient->sendMessage($chatId, "❌ هیچ مدل فعالی یافت نشد.");
                return;
            }

            $msg = "🎯 مدل مورد نظر را انتخاب کنید:\n\n";
            $keyboard = ['inline_keyboard' => []];

            foreach ($models as $m) {
                $displayName = $m['display_name'] ?? $m['name'];
                $desc = $m['description'] ?? '';
                $free = $m['free_model'] ? '🆓 رایگان' : '';
                $inCost = $m['cost_per_input_char'] ?? 0;
                $outCost = $m['cost_per_output_char'] ?? 0;
                
                // Show description + cost in message body
                $msg .= "• {$displayName}";
                if ($free) $msg .= " ({$free})";
                $msg .= "\n  💰 ورودی: {$inCost}/char | خروجی: {$outCost}/char";
                if ($desc) $msg .= "\n  📌 {$desc}";
                $msg .= "\n\n";
                
                // Button: only display_name
                $keyboard['inline_keyboard'][] = [[
                    'text' => $displayName,
                    'callback_data' => "chat_pick_model_{$m['id']}"
                ]];
            }

            $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'start_chat']];

            $db->query("REPLACE INTO bot_state (user_id, state) VALUES (?, 'chat_selecting_model')", [$internalId]);
            $this->baleClient->sendMessage($chatId, $msg, $keyboard);
        } catch (\Throwable $e) {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در دریافت لیست مدل‌ها.");
        }
    }

    private function startChatWithModel(int $chatId, int $userId, int $modelId): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $db = Database::getInstance();
        $model = $db->query("SELECT * FROM ai_text_models WHERE id = ? AND is_active = 1", [$modelId])->fetch();

        if (!$model) {
            $this->baleClient->sendMessage($chatId, "❌ مدل یافت نشد.");
            return;
        }

        $this->createAndStartConversation($chatId, $internalId, $userId, $model);
    }

    // ─────────────────────────────────────────────
    //   CONVERSATION MANAGEMENT
    // ─────────────────────────────────────────────

    private function createAndStartConversation(int $chatId, int $internalId, int $baleUserId, array $model): void
    {
        $db = Database::getInstance();
        $modelName = $model['name'];
        $provider = $model['provider'] ?? 'openrouter';

        // Create conversation
        $title = 'مکالمه ' . date('Y-m-d H:i');
        $db->query(
            "INSERT INTO chat_conversations (user_id, model, title) VALUES (?, ?, ?)",
            [$internalId, $modelName, $title]
        );
        $convId = $db->lastInsertId();

        // Set bot state
        $extra = json_encode([
            'conv_id' => $convId,
            'model_id' => (int)$model['id'],
            'model_name' => $modelName,
            'provider' => $provider,
        ]);
        $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'chat_active', ?)", [$internalId, $extra]);

        $free = $model['free_model'] ? '🆓 رایگان' : '';
        $inCost = $model['cost_per_input_char'] ?? 0;
        $outCost = $model['cost_per_output_char'] ?? 0;
        $formats = $model['supported_formats'] ?? 'txt,doc,pdf,jpg,jpeg,png,gif,webp';
        $displayName = $model['display_name'] ?? $modelName;

        $msg = "✅ گفتگو با مدل «{$displayName}» آغاز شد.\n\n"
             . "📊 هزینه:\n"
             . "  💰 ورودی: {$inCost} اعتبار/کاراکتر\n"
             . "  💰 خروجی: {$outCost} اعتبار/کاراکتر\n"
             . ($free ? "  🆓 این مدل رایگان است\n" : "")
             . "\n📁 فرمت‌های پشتیبانی شده:\n"
             . "  {$formats}\n"
             . "\n📝 پیام خود را بنویسید.\n"
             . "📸 می‌توانید عکس یا فایل با فرمت‌های مجاز ارسال کنید.\n"
             . "🚪 /exit برای خروج از مکالمه.";

        $this->baleClient->sendMessage($chatId, $msg, $this->getChatActiveKeyboard());
    }

    private function resumeConversation(int $chatId, int $userId, int $convId): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $db = Database::getInstance();
        $conv = $db->query("SELECT * FROM chat_conversations WHERE id = ? AND user_id = ?", [$convId, $internalId])->fetch();
        if (!$conv) {
            $this->baleClient->sendMessage($chatId, "❌ مکالمه یافت نشد.");
            return;
        }

        $modelName = $conv['model'];
        $model = $db->query("SELECT * FROM ai_text_models WHERE name = ? AND is_active = 1", [$modelName])->fetch();

        $extra = json_encode([
            'conv_id' => $convId,
            'model_id' => $model ? (int)$model['id'] : 0,
            'model_name' => $modelName,
            'provider' => $model['provider'] ?? 'openrouter',
        ]);
        $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'chat_active', ?)", [$internalId, $extra]);

        // Get ALL messages, build a 4096-char summary from the end
        $allMsgs = $db->query(
            "SELECT role, content FROM chat_messages WHERE conversation_id = ? ORDER BY id ASC",
            [$convId]
        )->fetchAll();

        $summary = "📋 ادامه مکالمه قبلی:\n━━━━━━━━━━━━━━\n";
        $maxLen = 4096 - 200; // reserve space for header/footer
        $lines = [];
        $totalLen = 0;

        // Process from newest to oldest
        foreach (array_reverse($allMsgs) as $m) {
            $label = $m['role'] === 'user' ? '(شما)' : '(AI)';
            $content = trim($m['content'] ?? '');
            // Truncate very long individual messages
            if (mb_strlen($content) > 500) {
                $content = mb_substr($content, 0, 500) . '...';
            }
            $line = "{$label}: {$content}\n";
            $lineLen = mb_strlen($line);

            if ($totalLen + $lineLen > $maxLen) {
                break; // stop adding older messages
            }

            array_unshift($lines, $line); // prepend so oldest-first order
            $totalLen += $lineLen;
        }

        $summary .= implode('', $lines);
        $summary .= "━━━━━━━━━━━━━━\n✏️ پیام خود را بنویسید.";

        $this->baleClient->sendMessage($chatId, $summary, $this->getChatActiveKeyboard());
    }

    private function deleteConversation(int $chatId, int $userId, int $convId): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $db = Database::getInstance();
        $db->query("DELETE FROM chat_conversations WHERE id = ? AND user_id = ?", [$convId, $internalId]);

        $this->baleClient->sendMessage($chatId, "🗑️ مکالمه حذف شد.");
        $this->showConversationHistory($chatId, $userId);
    }

    private function exitChat(int $chatId, int $userId): void
    {
        $internalId = $this->resolveUserId($userId);
        if ($internalId) {
            $db = Database::getInstance();
            $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
            if ($stateData) {
                $extra = json_decode($stateData['extra_data'] ?? '{}', true);
                $convId = $extra['conv_id'] ?? 0;
                if ($convId) {
                    $db->query("UPDATE chat_conversations SET status = 'archived' WHERE id = ?", [$convId]);
                }
            }
            $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
        }
        $this->baleClient->sendMessage($chatId, "🚪 از مکالمه خارج شدید.\nبرای شروع دوباره از منوی اصلی استفاده کنید.");
    }

    // ─────────────────────────────────────────────
    //   PROCESS MESSAGES
    // ─────────────────────────────────────────────

    private function processChatMessage(int $chatId, int $userId, string $text, ?string $fileContent, ?string $fileType): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) {
            \Core\AILogger::error('chat', 'resolveUserId failed', ['bale_user_id' => $userId]);
            $this->baleClient->sendMessage($chatId, "❌ کاربر یافت نشد.");
            return;
        }

        $db = Database::getInstance();
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $convId = (int)($extra['conv_id'] ?? 0);
        $modelId = (int)($extra['model_id'] ?? 0);
        $modelName = $extra['model_name'] ?? '';
        $provider = $extra['provider'] ?? 'openrouter';

        \Core\AILogger::log('CHAT_PROCESS', [
            'internal_id' => $internalId,
            'conv_id' => $convId,
            'model_id' => $modelId,
            'model_name' => $modelName,
            'provider' => $provider,
            'text_len' => mb_strlen($text),
        ]);

        if (!$convId || !$modelId) {
            \Core\AILogger::error('chat', 'Missing conv_id or model_id', $extra);
            $this->baleClient->sendMessage($chatId, "❌ خطا در بازیابی مکالمه. دوباره شروع کنید.");
            return;
        }

        // Get model cost settings from text models table
        $model = $db->query("SELECT * FROM ai_text_models WHERE id = ?", [$modelId])->fetch();
        if (!$model) {
            \Core\AILogger::error('chat', 'Model not found in ai_text_models', ['model_id' => $modelId, 'model_name' => $modelName]);
            // Fallback: use state data
            $model = [
                'id' => $modelId,
                'name' => $modelName,
                'provider' => $provider,
                'cost_per_input_char' => 0.000001,
                'cost_per_output_char' => 0.000002,
                'free_model' => 0,
            ];
        }

        $costPerInput = (float)($model['cost_per_input_char'] ?? 0);
        $costPerOutput = (float)($model['cost_per_output_char'] ?? 0);
        $freeModel = (int)($model['free_model'] ?? 0);
        $modelProvider = $model['provider'] ?? 'openrouter';

        // Calculate file chars
        $fileChars = 0;
        if ($fileContent !== null && $fileType !== null) {
            $fileChars = ChatService::estimateFileChars($fileType, $fileContent);
        }

        // Calculate input cost
        $inputChars = mb_strlen($text) + $fileChars;
        $inputCost = $freeModel ? 0 : ChatService::calcCreditCost($inputChars, $costPerInput);

        // Check & deduct input credits
        if (!$freeModel && $inputCost > 0) {
            if (!CreditService::hasEnoughCredit($internalId, $inputCost)) {
                $buyCreditKeyboard = [
                    'inline_keyboard' => [
                        [['text' => "\xF0\x9F\x92\xB3 برای افزایش اعتبار کلیک کن", 'callback_data' => 'buy_credit']],
                    ]
                ];
                $this->baleClient->sendMessage($chatId, "❌ اعتبار کافی ندارید (نیاز به {$inputCost} اعتبار). لطفاً حساب خود را شارژ کنید.", $buyCreditKeyboard);
                return;
            }
            $refId = 'chat_in_' . $convId . '_' . time();
            if (!CreditService::deduct($internalId, $inputCost, $refId)) {
                $this->baleClient->sendMessage($chatId, "⚠️ خطا در کسر اعتبار.");
                return;
            }
        }

        // Save user message
        $db->query(
            "INSERT INTO chat_messages (conversation_id, role, content, file_type, file_content, input_chars, output_chars, cost_input_credits, cost_output_credits) VALUES (?, 'user', ?, ?, ?, ?, 0, ?, 0)",
            [$convId, $text, $fileType, $fileContent, $inputChars, $inputCost]
        );

        $loadingMsgId = $this->baleClient->sendMessage($chatId, "⏳ در حال دریافت پاسخ...");

        // Build messages for OpenRouter
        $history = $db->query(
            "SELECT role, content, file_type, file_content FROM chat_messages WHERE conversation_id = ? ORDER BY id ASC",
            [$convId]
        )->fetchAll();

        // ─── MEMORY MODULE: Inject memory context before AI call ───
        $memoryManager = null;
        try {
            $memoryManager = new MemoryManager();
            $memoryHooks = new MemoryHooks($memoryManager);
            
            // Build messages from history first
            $orMessages = ChatService::buildMessagesFromHistory($history);
            
            // Get or create system prompt for memory context
            $systemPrompt = '';
            $filteredHistory = array_filter($history, fn($h) => $h['role'] === 'system');
            if (!empty($filteredHistory)) {
                $firstSystem = reset($filteredHistory);
                $systemPrompt = $firstSystem['content'] ?? '';
            }
            
            // Inject memory context ONLY ONCE per conversation (convKey tracks it)
            if (!empty($text)) {
                $convKey = 'conv_' . ($convId ?? 0);
                $memoryHooks->onBeforeChatRequest($internalId, $systemPrompt, $convKey);
            }
            
            // If system prompt was modified (memory added), prepend it
            if (!empty($systemPrompt)) {
                $orMessages = array_merge(
                    [['role' => 'system', 'content' => $systemPrompt]],
                    array_filter($orMessages, fn($m) => ($m['role'] ?? '') !== 'system')
                );
            }
        } catch (\Throwable $e) {
            \Core\AILogger::error('memory', 'Inject error', ['error' => $e->getMessage()]);
            $orMessages = ChatService::buildMessagesFromHistory($history);
        }

        // Send to OpenRouter
        $chatService = new ChatService();
        $result = $chatService->chat($orMessages, $modelName, $model);

        if (isset($result['error'])) {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا: " . $result['error']);
            // Refund input cost
            if (!$freeModel && $inputCost > 0) {
                CreditService::addCredits($internalId, $inputCost, 'chat_refund_' . $convId . '_' . time());
            }
            return;
        }

        $responseText = $result['response'];
        $outputChars = $result['output_chars'];

        // Use actual cost from OpenRouter API response (usage.cost in USD)
        // Convert USD to Toman using dollar_rate + profit_margin from settings
        $actualCostUsd = (float)($result['cost_usd'] ?? 0);
        $outputCost = 0;

        if ($freeModel) {
            // Free model — no charge
            $outputCost = 0;
        } elseif ($actualCostUsd > 0) {
            // API returned actual cost — use it for precise billing
            // Get dollar_rate and profit_margin from settings
            $settingsRow = $db->query("SELECT value FROM settings WHERE key_name = 'dollar_rate'")->fetch();
            $dollarRate = (float)($settingsRow['value'] ?? 231000);
            $settingsRow = $db->query("SELECT value FROM settings WHERE key_name = 'profit_margin_percent'")->fetch();
            $profitPercent = (float)($settingsRow['value'] ?? 25);

            // Convert USD to Toman: cost_usd * dollar_rate * (1 + profit_margin/100)
            // Then convert Toman to credits (1 credit = 1000 Toman)
            $costToman = $actualCostUsd * $dollarRate * (1 + $profitPercent / 100);
            $outputCost = round($costToman / 1000, 6);
        } else {
            // Fallback: API did not return cost (e.g. GapGPT, MetisAI)
            // Use char-based calculation as before
            $outputCost = $freeModel ? 0 : ChatService::calcCreditCost($outputChars, $costPerOutput);
        }

        // Deduct output cost
        if (!$freeModel && $outputCost > 0) {
            $refOut = 'chat_out_' . $convId . '_' . time();
            CreditService::deduct($internalId, $outputCost, $refOut);
        }

        // ─── MEMORY MODULE: Process after AI response ───
        if ($memoryManager && $memoryManager->isEnabled()) {
            try {
                $memoryHooks->onAfterChatResponse($internalId, $text);
            } catch (\Throwable $e) {
                \Core\AILogger::error('memory', 'onAfterChatResponse failed', ['error' => $e->getMessage()]);
            }
        }

        // Save assistant message
        $db->query(
            "INSERT INTO chat_messages (conversation_id, role, content, input_chars, output_chars, cost_input_credits, cost_output_credits) VALUES (?, 'assistant', ?, 0, ?, 0, ?)",
            [$convId, $responseText, $outputChars, $outputCost]
        );

        // Update conversation totals
        $db->query(
            "UPDATE chat_conversations SET 
                total_input_chars = total_input_chars + ?,
                total_output_chars = total_output_chars + ?,
                total_cost_credits = total_cost_credits + ? + ?
             WHERE id = ?",
            [$inputChars, $outputChars, $inputCost, $outputCost, $convId]
        );

        // Auto-title on first exchange
        $msgCount = $db->query("SELECT COUNT(*) as c FROM chat_messages WHERE conversation_id = ?", [$convId])->fetch()['c'] ?? 0;
        if ($msgCount <= 2) {
            $shortTitle = mb_substr($text, 0, 50) . (mb_strlen($text) > 50 ? '...' : '');
            $db->query("UPDATE chat_conversations SET title = ? WHERE id = ?", [$shortTitle, $convId]);
        }

        // Delete loading message
        if ($loadingMsgId !== false) {
            $this->baleClient->deleteMessage($chatId, $loadingMsgId);
        }

        // Send response
        $costMsg = $freeModel ? '' : "\n💎 هزینه: {$inputCost} ورودی + {$outputCost} خروجی = " . ($inputCost + $outputCost) . " اعتبار";
        $fullMsg = mb_substr($responseText, 0, 3800) . $costMsg;
        $this->baleClient->sendMessage($chatId, $fullMsg, $this->getChatActiveKeyboard());

        // If response is long, send remaining
        if (mb_strlen($responseText) > 3800) {
            $remaining = mb_substr($responseText, 3800);
            $this->baleClient->sendMessage($chatId, $remaining);
        }
    }

    // ─────────────────────────────────────────────
    //   FILE HANDLING
    // ─────────────────────────────────────────────

    private function handlePhotoInChat(int $chatId, int $userId, $update, ?string $caption): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) {
            $this->baleClient->sendMessage($chatId, "❌ کاربر یافت نشد.");
            return;
        }

        $db = Database::getInstance();
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $modelId = (int)($extra['model_id'] ?? 0);

        // Validate file format against model's supported_formats
        if ($modelId > 0) {
            $model = $db->query("SELECT supported_formats FROM ai_text_models WHERE id = ?", [$modelId])->fetch();
            if ($model && !empty($model['supported_formats'])) {
                $formats = explode(',', strtolower($model['supported_formats']));
                // Check for image formats
                $supportedImage = array_intersect($formats, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                if (empty($supportedImage)) {
                    $msg = "❌ این مدل از تصاویر پشتیبانی نمی‌کند.\n"
                         . "📁 فرمت‌های مجاز: {$model['supported_formats']}\n"
                         . "لطفاً فایل با فرمت مجاز ارسال کنید.";
                    $this->baleClient->sendMessage($chatId, $msg);
                    return;
                }
            }
        }

        $fileId = $update->getPhotoFileId();
        $caption = trim($caption ?? '');

        if (empty($caption)) {
            $caption = "توضیحی برای این تصویر بنویسید.";
        }

        // Download photo
        $fileContent = $this->downloadPhoto($fileId);
        if ($fileContent === null) {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در دریافت تصویر.");
            return;
        }

        // Detect mime
        $mime = 'image/jpeg';
        $first = substr($fileContent, 0, 4);
        if (str_starts_with($first, "\x89PNG")) $mime = 'image/png';
        elseif (str_starts_with($first, "\xff\xd8")) $mime = 'image/jpeg';

        $dataUri = 'data:' . $mime . ';base64,' . base64_encode($fileContent);

        $this->processChatMessage($chatId, $userId, $caption, $dataUri, 'image');
    }

    /**
     * Handle document/file upload in chat — validates extension against model's supported_formats.
     */
    private function handleDocumentInChat(int $chatId, int $userId, $update, ?string $caption): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) {
            $this->baleClient->sendMessage($chatId, "❌ کاربر یافت نشد.");
            return;
        }

        $db = Database::getInstance();
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $modelId = (int)($extra['model_id'] ?? 0);

        $fileName = $update->getDocumentFileName() ?? '';
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // Validate file extension against model's supported_formats
        if ($modelId > 0 && !empty($fileExtension)) {
            $model = $db->query("SELECT supported_formats FROM ai_text_models WHERE id = ?", [$modelId])->fetch();
            if ($model && !empty($model['supported_formats'])) {
                $formats = explode(',', strtolower($model['supported_formats']));
                if (!in_array($fileExtension, $formats)) {
                    $msg = "❌ فرمت «{$fileExtension}» توسط این مدل پشتیبانی نمی‌شود.\n"
                         . "📁 فرمت‌های مجاز: {$model['supported_formats']}\n"
                         . "لطفاً فایل با فرمت مجاز ارسال کنید.";
                    $this->baleClient->sendMessage($chatId, $msg);
                    return;
                }
            }
        }

        $caption = trim($caption ?? '');
        if (empty($caption)) {
            $caption = "لطفاً این فایل را پردازش کن: {$fileName}";
        }

        // Download file
        $fileId = $update->getDocumentFileId();
        $fileContent = $this->downloadPhoto($fileId); // downloadPhoto works for any file download
        if ($fileContent === null) {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در دریافت فایل.");
            return;
        }

        $this->processChatMessage($chatId, $userId, $caption, $fileContent, $fileExtension);
    }

    private function downloadPhoto(string $fileId): ?string
    {
        $token = \Core\Config::get('BALE_BOT_TOKEN');
        $url = "https://tapi.bale.ai/file/bot{$token}/{$fileId}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || strlen($data ?? '') < 100) return null;
        return $data;
    }

    // ─────────────────────────────────────────────
    //   HISTORY
    // ─────────────────────────────────────────────

    private function showConversationHistory(int $chatId, int $userId, int $page = 0): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $db = Database::getInstance();

        // Get per-page limit from settings (default 10)
        $limitRow = $db->query("SELECT value FROM settings WHERE key_name = 'chat_history_per_page'")->fetch();
        $perPage = (int)($limitRow['value'] ?? 10);
        if ($perPage < 1) $perPage = 10;

        $offset = $page * $perPage;

        // Count total
        $countRow = $db->query("SELECT COUNT(*) as c FROM chat_conversations WHERE user_id = ?", [$internalId])->fetch();
        $total = (int)($countRow['c'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));

        if ($page >= $totalPages) {
            $page = $totalPages - 1;
            $offset = $page * $perPage;
        }

        $convs = $db->query(
            "SELECT id, model, title, total_input_chars, total_output_chars, total_cost_credits, status, 
                    (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = chat_conversations.id) as msg_count,
                    created_at 
             FROM chat_conversations 
             WHERE user_id = ? 
             ORDER BY updated_at DESC 
             LIMIT ? OFFSET ?",
            [$internalId, $perPage, $offset]
        )->fetchAll();

        if (empty($convs)) {
            $this->baleClient->sendMessage($chatId,
                "📋 تاریخچه گفتگوهای شما خالی است.\n"
              . "از منوی اصلی «💬 گفتگوی هوش» را انتخاب کنید."
            , $this->getChatEntryKeyboard());
            return;
        }

        $msg = "📋 تاریخچه گفتگوهای شما (صفحه " . ($page + 1) . " از {$totalPages}):\n\n";
        $keyboard = ['inline_keyboard' => []];

        foreach ($convs as $c) {
            $icon = $c['status'] === 'active' ? '💬' : '📁';
            $title = $c['title'] ? htmlspecialchars(mb_substr($c['title'], 0, 40)) : 'بدون عنوان';
            $model = htmlspecialchars($c['model']);
            $msgCount = $c['msg_count'];
            $cost = $c['total_cost_credits'];
            $created = substr($c['created_at'], 0, 16);

            $msg .= "{$icon} **{$title}**\n  مدل: {$model} | {$msgCount} پیام | {$cost} اعتبار\n  {$created}\n\n";

            $keyboard['inline_keyboard'][] = [
                ['text' => "▶️ {$title}", 'callback_data' => "chat_resume_{$c['id']}"]
            ];
        }

        // Pagination row
        $navRow = [];
        if ($page > 0) {
            $navRow[] = ['text' => '◀️ قبلی', 'callback_data' => 'chat_history_page_' . ($page - 1)];
        }
        $navRow[] = ['text' => "📄 {$page}/{$totalPages}", 'callback_data' => 'chat_history'];
        if ($page + 1 < $totalPages) {
            $navRow[] = ['text' => 'بعدی ▶️', 'callback_data' => 'chat_history_page_' . ($page + 1)];
        }
        if (!empty($navRow)) {
            $keyboard['inline_keyboard'][] = $navRow;
        }

        $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'start_chat']];

        $db->query("REPLACE INTO bot_state (user_id, state) VALUES (?, 'chat_viewing_history')", [$internalId]);
        $this->baleClient->sendMessage($chatId, $msg, $keyboard);
    }

    // ─────────────────────────────────────────────
    //   KEYBOARDS
    // ─────────────────────────────────────────────

    private function getChatEntryKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '✅ بله، مدل پیش‌فرض', 'callback_data' => 'chat_use_default']],
                [['text' => '🎯 انتخاب مدل', 'callback_data' => 'chat_select_model']],
                [['text' => '📋 تاریخچه گفتگوها', 'callback_data' => 'chat_history']],
            ]
        ];
    }

    private function getChatActiveKeyboard(): array
    {
        return [
            'keyboard' => [[['text' => '/exit'], ['text' => '/cancel']], [['text' => 'منو اصلی']]],
            'resize_keyboard' => true,
        ];
    }

    // ─────────────────────────────────────────────
    //   HELPERS
    // ─────────────────────────────────────────────

    private function resolveUserId(int $baleUserId): ?int
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT id FROM users WHERE bale_user_id = ?", [$baleUserId]);
            $row = $stmt->fetch();
            return $row ? (int) $row['id'] : null;
        } catch (\Throwable $e) { return null; }
    }

    private function getUserState(int $baleUserId): ?string
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query(
                "SELECT bs.state FROM bot_state bs 
                 JOIN users u ON bs.user_id = u.id 
                 WHERE u.bale_user_id = ?",
                [$baleUserId]
            );
            $row = $stmt->fetch();
            return $row['state'] ?? null;
        } catch (\Throwable $e) { return null; }
    }
}