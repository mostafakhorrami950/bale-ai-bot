<?php

namespace Modules\Bot\Handlers;

use Modules\AI\AIService;
use Modules\AI\ChatService;
use Modules\Bot\CreditService;
use Modules\Memory\MemoryManager;
use Modules\Memory\Hooks as MemoryHooks;
use Database\Database;
use Database\Logger;
use Core\BotTextService;

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

            if ($text === '/exit' || $text === '🚪 خروج') {
                $this->exitChat($chatId, $userId);
                return;
            }

            if ($callbackData === 'start_chat' || $text === '💬 گفتگوی هوش') {
                $this->showChatMenu($chatId, $userId);
                return;
            }

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

            if ($state === 'chat_viewing_history') {
                $this->showConversationHistory($chatId, $userId);
                return;
            }

            if ($state === 'chat_active') {
                if (!$this->checkMembership($userId, $chatId)) return;
                if ($update->hasPhoto()) {
                    $this->handlePhotoInChat($chatId, $userId, $update, $text);
                    return;
                }
                if ($update->hasDocument()) {
                    $this->handleDocumentInChat($chatId, $userId, $update, $text);
                    return;
                }
                if ($update->hasVoice()) {
                    $this->handleAudioInChat($chatId, $userId, $update, $text, 'voice');
                    return;
                }
                if ($update->hasAudio()) {
                    $this->handleAudioInChat($chatId, $userId, $update, $text, 'audio');
                    return;
                }
                if ($update->hasVideo()) {
                    $this->handleVideoInChat($chatId, $userId, $update, $text);
                    return;
                }
                if (!empty(trim($text ?? ''))) {
                    $this->processChatMessage($chatId, $userId, trim($text), null, null);
                    return;
                }
                $this->baleClient->sendMessage($chatId, BotTextService::get('chat_enter_message'));
                return;
            }

            if ($state === 'chat_selecting_model') {
                $this->showChatModelList($chatId, $userId);
                return;
            }

            $this->baleClient->sendMessage($chatId, "🤖 لطفاً از منوی زیر انتخاب کنید:", $this->getChatEntryKeyboard());

        } catch (\Throwable $e) {
            error_log("ChatHandler FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            if (isset($chatId)) {
                $this->baleClient->sendMessage($chatId, BotTextService::get('chat_error'));
            }
        }
    }

    private function showChatMenu(int $chatId, int $userId): void
    {
        $this->baleClient->sendMessage($chatId,
            BotTextService::get('chat_menu_title'),
            $this->getChatEntryKeyboard()
        );
    }

    private function startWithDefaultModel(int $chatId, int $userId): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_user_not_found'));
            return;
        }

        $aiService = new AIService();
        $model = $aiService->getFirstActiveTextModel();

        if (!$model) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_no_text_models'));
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
                $this->baleClient->sendMessage($chatId, BotTextService::get('chat_model_list_error'));
                return;
            }
            $msg = BotTextService::get('chat_model_list_title');
            $keyboard = ['inline_keyboard' => []];
            foreach ($models as $m) {
                $displayName = $m['display_name'] ?? $m['name'];
                $desc = $m['description'] ?? '';
                $free = $m['free_model'] ? '🆓 رایگان' : '';
                $inCost = $m['cost_per_input_char'] ?? 0;
                $outCost = $m['cost_per_output_char'] ?? 0;
                $msg .= "• {$displayName}";
                if ($free) $msg .= " ({$free})";
                $msg .= "\n  💰 ورودی: {$inCost}/char | خروجی: {$outCost}/char";
                if ($desc) $msg .= "\n  📌 {$desc}";
                $msg .= "\n\n";
                $keyboard['inline_keyboard'][] = [[
                    'text' => $displayName,
                    'callback_data' => "chat_pick_model_{$m['id']}"
                ]];
            }
            $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'start_chat']];
            $db->query("REPLACE INTO bot_state (user_id, state) VALUES (?, 'chat_selecting_model')", [$internalId]);
            $this->baleClient->sendMessage($chatId, $msg, $keyboard);
        } catch (\Throwable $e) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_model_list_error'));
        }
    }

    private function startChatWithModel(int $chatId, int $userId, int $modelId): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;
        $db = Database::getInstance();
        $model = $db->query("SELECT * FROM ai_text_models WHERE id = ? AND is_active = 1", [$modelId])->fetch();
        if (!$model) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_model_not_found'));
            return;
        }
        $this->createAndStartConversation($chatId, $internalId, $userId, $model);
    }

    private function createAndStartConversation(int $chatId, int $internalId, int $baleUserId, array $model): void
    {
        $db = Database::getInstance();
        $modelName = $model['name'];
        $provider = $model['provider'] ?? 'openrouter';
        $title = 'مکالمه ' . date('Y-m-d H:i');
        $db->query("INSERT INTO chat_conversations (user_id, model, title) VALUES (?, ?, ?)", [$internalId, $modelName, $title]);
        $convId = $db->lastInsertId();
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
        $freeText = $free ? "  🆓 این مدل رایگان است\n" : '';
        $msg = BotTextService::get('chat_conversation_started', [
            'display_name' => $displayName,
            'in_cost' => $inCost,
            'out_cost' => $outCost,
            'free_text' => $freeText,
            'formats' => $formats,
        ]);
        $this->baleClient->sendMessage($chatId, $msg, $this->getChatActiveKeyboard());
    }

    private function resumeConversation(int $chatId, int $userId, int $convId): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;
        $db = Database::getInstance();
        $conv = $db->query("SELECT * FROM chat_conversations WHERE id = ? AND user_id = ?", [$convId, $internalId])->fetch();
        if (!$conv) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_conversation_not_found'));
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
        $allMsgs = $db->query("SELECT role, content FROM chat_messages WHERE conversation_id = ? ORDER BY id ASC", [$convId])->fetchAll();
        $summary = BotTextService::get('chat_resume_header');
        $maxLen = 4096 - 200;
        $lines = [];
        $totalLen = 0;
        foreach (array_reverse($allMsgs) as $m) {
            $label = $m['role'] === 'user' ? '(شما)' : '(AI)';
            $content = trim($m['content'] ?? '');
            if (mb_strlen($content) > 500) {
                $content = mb_substr($content, 0, 500) . '...';
            }
            $line = "{$label}: {$content}\n";
            $lineLen = mb_strlen($line);
            if ($totalLen + $lineLen > $maxLen) break;
            array_unshift($lines, $line);
            $totalLen += $lineLen;
        }
        $summary .= implode('', $lines);
        $summary .= BotTextService::get('chat_resume_footer');
        $this->baleClient->sendMessage($chatId, $summary, $this->getChatActiveKeyboard());
    }

    private function deleteConversation(int $chatId, int $userId, int $convId): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;
        $db = Database::getInstance();
        $db->query("DELETE FROM chat_conversations WHERE id = ? AND user_id = ?", [$convId, $internalId]);
        $this->baleClient->sendMessage($chatId, BotTextService::get('chat_conversation_deleted'));
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
        $this->baleClient->sendMessage($chatId, BotTextService::get('chat_exit_message'));
    }

    private function processChatMessage(int $chatId, int $userId, string $text, ?string $fileContent, ?string $fileType, ?string $localFilePath = null): void
    {

        $internalId = $this->resolveUserId($userId);
        if (!$internalId) {
            \Core\AILogger::error('chat', 'resolveUserId failed', ['bale_user_id' => $userId]);
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_user_not_found'));
            return;
        }

        $db = Database::getInstance();
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $convId = (int)($extra['conv_id'] ?? 0);
        $modelId = (int)($extra['model_id'] ?? 0);
        $modelName = $extra['model_name'] ?? '';
        $provider = $extra['provider'] ?? 'openrouter';

        if (!$convId || !$modelId) {
            \Core\AILogger::error('chat', 'Missing conv_id or model_id', $extra);
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_state_error'));
            return;
        }

        $model = $db->query("SELECT * FROM ai_text_models WHERE id = ?", [$modelId])->fetch();
        if (!$model) {
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

        $fileChars = 0;
        if ($fileContent !== null && $fileType !== null) {
            $fileChars = ChatService::estimateFileChars($fileType, $fileContent);
        }
        $inputChars = mb_strlen($text) + $fileChars;
        $inputCost = $freeModel ? 0 : ChatService::calcCreditCost($inputChars, $costPerInput);

        if (!$freeModel && $inputCost > 0) {
            if (!CreditService::hasEnoughCredit($internalId, $inputCost)) {
                $buyCreditKeyboard = [
                    'inline_keyboard' => [
                        [['text' => "\xF0\x9F\x92\xB3 برای افزایش اعتبار کلیک کن", 'callback_data' => 'buy_credit']],
                    ]
                ];
                $this->baleClient->sendMessage($chatId, BotTextService::get('chat_credit_error', ['cost' => $inputCost]), $buyCreditKeyboard);
                return;
            }
            $refId = 'chat_in_' . $convId . '_' . time();
            if (!CreditService::deduct($internalId, $inputCost, $refId)) {
                $this->baleClient->sendMessage($chatId, BotTextService::get('chat_credit_deduct_error'));
                return;
            }
        }

        try {
            $db->query(
                "INSERT INTO chat_messages (conversation_id, role, content, file_type, file_content, input_chars, output_chars, cost_input_credits, cost_output_credits, model_name) VALUES (?, 'user', ?, ?, ?, ?, 0, ?, 0, ?)",
                [$convId, $text, $fileType, $fileContent, $inputChars, $inputCost, $modelName]
            );
        } catch (\Throwable $e) {
            $db->query(
                "INSERT INTO chat_messages (conversation_id, role, content, file_type, file_content, input_chars, output_chars, cost_input_credits, cost_output_credits) VALUES (?, 'user', ?, ?, ?, ?, 0, ?, 0)",
                [$convId, $text, $fileType, $fileContent, $inputChars, $inputCost]
            );
        }

        $loadingMsgId = $this->baleClient->sendMessage($chatId, BotTextService::get('chat_processing'));

        $history = $db->query(
            "SELECT role, content, file_type, file_content FROM chat_messages WHERE conversation_id = ? ORDER BY id ASC",
            [$convId]
        )->fetchAll();

        $memoryManager = null;
        try {
            $memoryManager = new MemoryManager();
            $memoryHooks = new MemoryHooks($memoryManager);
            $orMessages = ChatService::buildMessagesFromHistory($history);
            $systemPrompt = '';
            $filteredHistory = array_filter($history, fn($h) => $h['role'] === 'system');
            if (!empty($filteredHistory)) {
                $firstSystem = reset($filteredHistory);
                $systemPrompt = $firstSystem['content'] ?? '';
            }
            if (!empty($text)) {
                $convKey = 'conv_' . ($convId ?? 0);
                $memoryHooks->onBeforeChatRequest($internalId, $systemPrompt, $convKey);
            }
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

        $chatService = new ChatService();
        $result = $chatService->chat($orMessages, $modelName, $model);

        // NOTE: Temp files (PDFs, docs etc.) are kept on disk for history references.
        // They're cleaned up periodically via the existing temp directory cleanup in repair_db.
        // DO NOT delete here — the URL is stored in chat_messages and may be referenced again.

        if (isset($result['error'])) {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا: " . $result['error']);
            if (!$freeModel && $inputCost > 0) {
                CreditService::addCredits($internalId, $inputCost, 'chat_refund_' . $convId . '_' . time());
            }
            return;
        }

        $responseText = $result['response'];
        $outputChars = $result['output_chars'];
        $actualCostUsd = (float)($result['cost_usd'] ?? 0);
        $outputCost = 0;

        if ($freeModel) {
            $outputCost = 0;
        } elseif ($actualCostUsd > 0) {
            $settingsRow = $db->query("SELECT value FROM settings WHERE key_name = 'dollar_rate'")->fetch();
            $dollarRate = (float)($settingsRow['value'] ?? 231000);
            $settingsRow = $db->query("SELECT value FROM settings WHERE key_name = 'profit_margin_percent'")->fetch();
            $profitPercent = (float)($settingsRow['value'] ?? 25);
            $costToman = $actualCostUsd * $dollarRate * (1 + $profitPercent / 100);
            $outputCost = round($costToman / 1000, 6);
        } else {
            $outputCost = $freeModel ? 0 : ChatService::calcCreditCost($outputChars, $costPerOutput);
        }

        if (!$freeModel && $outputCost > 0) {
            $refOut = 'chat_out_' . $convId . '_' . time();
            CreditService::deduct($internalId, $outputCost, $refOut);
        }

        // Skip memory summarization when user sent a file
        if ($memoryManager && $memoryManager->isEnabled() && $fileContent === null) {
            try {
                $memoryHooks->onAfterChatResponse($internalId, $text);
            } catch (\Throwable $e) {
                \Core\AILogger::error('memory', 'onAfterChatResponse failed', ['error' => $e->getMessage()]);
            }
        }

        $inputTokens = (int)($result['input_tokens'] ?? 0);
        $outputTokens = (int)($result['output_tokens'] ?? 0);
        try {
            $db->query(
                "INSERT INTO chat_messages (conversation_id, role, content, input_chars, output_chars, cost_input_credits, cost_output_credits, model_name, actual_cost_usd, input_tokens, output_tokens) VALUES (?, 'assistant', ?, 0, ?, 0, ?, ?, ?, ?, ?)",
                [$convId, $responseText, $outputChars, $outputCost, $modelName, $actualCostUsd, $inputTokens, $outputTokens]
            );
        } catch (\Throwable $e) {
            $db->query(
                "INSERT INTO chat_messages (conversation_id, role, content, input_chars, output_chars, cost_input_credits, cost_output_credits) VALUES (?, 'assistant', ?, 0, ?, 0, ?)",
                [$convId, $responseText, $outputChars, $outputCost]
            );
        }

        $db->query(
            "UPDATE chat_conversations SET total_input_chars = total_input_chars + ?, total_output_chars = total_output_chars + ?, total_cost_credits = total_cost_credits + ? + ? WHERE id = ?",
            [$inputChars, $outputChars, $inputCost, $outputCost, $convId]
        );

        $msgCount = $db->query("SELECT COUNT(*) as c FROM chat_messages WHERE conversation_id = ?", [$convId])->fetch()['c'] ?? 0;
        if ($msgCount <= 2) {
            $shortTitle = mb_substr($text, 0, 50) . (mb_strlen($text) > 50 ? '...' : '');
            $db->query("UPDATE chat_conversations SET title = ? WHERE id = ?", [$shortTitle, $convId]);
        }

        if ($loadingMsgId !== false) {
            $this->baleClient->deleteMessage($chatId, $loadingMsgId);
        }

        $costMsg = $freeModel ? '' : BotTextService::get('chat_cost_summary', ['input_cost' => $inputCost, 'output_cost' => $outputCost, 'total_cost' => ($inputCost + $outputCost)]);
        
        // Split long responses into multiple messages (Bale API limit: ~4000 chars per message)
        $maxMsgLen = 3800;
        $msgWithCost = $responseText . $costMsg;
        
        if (mb_strlen($msgWithCost) <= $maxMsgLen) {
            // Single message fits entirely
            $this->baleClient->sendMessage($chatId, $msgWithCost, $this->getChatActiveKeyboard());
        } else {
            // Need to split into multiple messages
            $remaining = $responseText;
            $isFirst = true;
            while (mb_strlen($remaining) > 0) {
                $chunk = mb_substr($remaining, 0, $maxMsgLen);
                $remaining = mb_substr($remaining, $maxMsgLen);
                
                if ($isFirst) {
                    // First message: include cost summary and keyboard
                    $firstMsg = $chunk;
                    if (!empty($costMsg)) {
                        $firstMsg = $chunk . $costMsg;
                    }
                    $this->baleClient->sendMessage($chatId, $firstMsg, $this->getChatActiveKeyboard());
                    $isFirst = false;
                } else {
                    // Subsequent messages: no keyboard, just content
                    $this->baleClient->sendMessage($chatId, $chunk);
                }
            }
        }
    }

    private function handlePhotoInChat(int $chatId, int $userId, $update, ?string $caption): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_user_not_found'));
            return;
        }
        $db = Database::getInstance();
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $modelId = (int)($extra['model_id'] ?? 0);

        if ($modelId > 0) {
            $model = $db->query("SELECT supported_formats FROM ai_text_models WHERE id = ?", [$modelId])->fetch();
            if ($model && !empty($model['supported_formats'])) {
                $formats = explode(',', strtolower($model['supported_formats']));
                $supportedImage = array_intersect($formats, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                if (empty($supportedImage)) {
                    $this->baleClient->sendMessage($chatId, BotTextService::get('chat_photo_format_error', ['formats' => $model['supported_formats']]));
                    return;
                }
            }
        }

        $fileId = $update->getPhotoFileId();
        $caption = trim($caption ?? '');
        if (empty($caption)) {
            $caption = BotTextService::get('chat_photo_caption');
        }

        $rawFileContent = $this->downloadPhoto($fileId);
        if ($rawFileContent === null) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_photo_error'));
            return;
        }

        // Detect MIME type from file header
        $mime = 'image/jpeg';
        $ext = 'jpg';
        $first = substr($rawFileContent, 0, 4);
        if (str_starts_with($first, "\x89PNG")) { $mime = 'image/png'; $ext = 'png'; }
        elseif (str_starts_with($first, "\xff\xd8")) { $mime = 'image/jpeg'; $ext = 'jpg'; }
        elseif (str_starts_with($first, "GIF8")) { $mime = 'image/gif'; $ext = 'gif'; }
        elseif (str_starts_with($first, "\x00\x00\x00\x1cftyp")) { $mime = 'image/mp4'; $ext = 'mp4'; }

        // Save to temp public directory with unique name (same as documents)
        $safeFilename = uniqid('img_', true) . '.' . $ext;
        $localDir = $this->tempDir;
        if (!is_dir($localDir)) {
            @mkdir($localDir, 0755, true);
        }
        $localPath = $localDir . $safeFilename;
        file_put_contents($localPath, $rawFileContent);

        // Generate public URL for OpenRouter to fetch the image directly
        $baseUrl = \Core\Config::get('SITE_BASE_URL', 'https://mobixai.ir');
        $publicUrl = $baseUrl . '/uploads/tmp/' . $safeFilename;

        // Pass public URL as fileContent (same logic as handleDocumentInChat)
        $this->processChatMessage($chatId, $userId, $caption, $publicUrl, 'image', $localPath);
    }

    /**
     * Handle document/file upload — save to server, send public URL to OpenRouter, delete after response.
     */
    private function handleDocumentInChat(int $chatId, int $userId, $update, ?string $caption): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_user_not_found'));
            return;
        }

        $db = Database::getInstance();
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $modelId = (int)($extra['model_id'] ?? 0);

        $fileName = $update->getDocumentFileName() ?? '';
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($modelId > 0 && !empty($fileExtension)) {
            $model = $db->query("SELECT supported_formats FROM ai_text_models WHERE id = ?", [$modelId])->fetch();
            if ($model && !empty($model['supported_formats'])) {
                $formats = explode(',', strtolower($model['supported_formats']));
                if (!in_array($fileExtension, $formats)) {
                    $this->baleClient->sendMessage($chatId, BotTextService::get('chat_file_format_error', ['ext' => $fileExtension, 'formats' => $model['supported_formats']]));
                    return;
                }
            }
        }

        $caption = trim($caption ?? '');
        if (empty($caption)) {
            $caption = BotTextService::get('chat_file_caption', ['filename' => $fileName]);
        }

        $fileId = $update->getDocumentFileId();
        $rawFileContent = $this->downloadPhoto($fileId);
        if ($rawFileContent === null) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_download_error'));
            return;
        }

        // Save to temp public directory with unique name
        $safeFilename = uniqid('file_', true) . '.' . $fileExtension;
        $localDir = BASE_PATH . '/uploads/tmp/';
        if (!is_dir($localDir)) {
            @mkdir($localDir, 0755, true);
        }
        $localPath = $localDir . $safeFilename;
        file_put_contents($localPath, $rawFileContent);

        // Generate public URL for OpenRouter to fetch directly
        $baseUrl = \Core\Config::get('SITE_BASE_URL', 'https://mobixai.ir');
        $publicUrl = $baseUrl . '/uploads/tmp/' . $safeFilename;

        // IMPORTANT: Detect media type from file extension and route accordingly.
        // Sending images/audio/video as 'file' type causes Google Gemini errors.
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $audioExts = ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a', 'aiff', 'opus', 'wma'];
        $videoExts = ['mp4', 'mpeg', 'mov', 'webm', 'avi', 'mkv'];

        if (in_array($fileExtension, $imageExts)) {
            $actualFileType = 'image';
        } elseif (in_array($fileExtension, $audioExts)) {
            // Audio files sent as document: convert to base64 data URI for input_audio
            // OpenRouter requires audio as base64, not URL
            $mimeMap = [
                'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
                'aac' => 'audio/aac', 'flac' => 'audio/flac', 'm4a' => 'audio/mp4',
                'aiff' => 'audio/aiff', 'opus' => 'audio/ogg', 'wma' => 'audio/x-ms-wma',
            ];
            $mime = $mimeMap[$fileExtension] ?? 'audio/mpeg';
            $rawContent = file_get_contents($localPath);
            $dataUri = 'data:' . $mime . ';base64,' . base64_encode($rawContent);
            $actualFileType = 'input_audio';
            $publicUrl = $dataUri; // Override URL with data URI
        } elseif (in_array($fileExtension, $videoExts)) {
            $actualFileType = 'video_url';
        } else {
            $actualFileType = $fileExtension;
        }

        // Pass public URL as fileContent. buildMessagesFromHistory sends URL directly.
        $this->processChatMessage($chatId, $userId, $caption, $publicUrl, $actualFileType, $localPath);
    }

    /**
     * Handle voice/audio message in chat — download, save as base64 data URI, send to AI.
     * OpenRouter requires audio as base64-encoded data URI with format specification.
     */
    private function handleAudioInChat(int $chatId, int $userId, $update, ?string $caption, string $type): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_user_not_found'));
            return;
        }

        $db = Database::getInstance();
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $modelId = (int)($extra['model_id'] ?? 0);

        // Check model supports audio
        if ($modelId > 0) {
            $model = $db->query("SELECT supported_formats FROM ai_text_models WHERE id = ?", [$modelId])->fetch();
            if ($model && !empty($model['supported_formats'])) {
                $formats = explode(',', strtolower($model['supported_formats']));
                $supportedAudio = array_intersect($formats, ['wav', 'mp3', 'ogg', 'aac', 'flac', 'm4a', 'pcm16', 'pcm24', 'aiff']);
                if (empty($supportedAudio)) {
                    $this->baleClient->sendMessage($chatId, BotTextService::get('chat_audio_format_error', ['formats' => $model['supported_formats']]));
                    return;
                }
            }
        }

        // Get file_id based on type
        $fileId = ($type === 'voice') ? $update->getVoiceFileId() : $update->getAudioFileId();
        $mimeType = ($type === 'voice') ? $update->getVoiceMimeType() : $update->getAudioMimeType();

        $caption = trim($caption ?? '');
        if (empty($caption)) {
            $caption = BotTextService::get('chat_audio_caption', ['type' => $type === 'voice' ? 'صوتی' : 'صوت']);
        }

        // Download audio file
        $rawFileContent = $this->downloadPhoto($fileId);
        if ($rawFileContent === null) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_audio_download_error'));
            return;
        }

        // Determine format from MIME type
        $formatMap = [
            'audio/wav' => 'wav',
            'audio/wave' => 'wav',
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/opus' => 'ogg',
            'audio/aac' => 'aac',
            'audio/x-aac' => 'aac',
            'audio/flac' => 'flac',
            'audio/x-flac' => 'flac',
            'audio/mp4' => 'm4a',
            'audio/x-m4a' => 'm4a',
            'audio/aiff' => 'aiff',
            'audio/x-aiff' => 'aiff',
        ];
        $format = $formatMap[$mimeType] ?? 'wav';

        // Convert to base64 data URI
        $base64Data = base64_encode($rawFileContent);
        $dataUri = 'data:' . $mimeType . ';base64,' . $base64Data;

        // Save to temp for potential cleanup
        $ext = $format;
        $safeFilename = uniqid('audio_', true) . '.' . $ext;
        $localPath = $this->tempDir . $safeFilename;
        file_put_contents($localPath, $rawFileContent);

        // Pass as input_audio type — buildMessagesFromHistory will handle it
        $this->processChatMessage($chatId, $userId, $caption, $dataUri, 'input_audio', $localPath);
    }

    /**
     * Handle video message in chat — download, save as base64 data URI, send to AI.
     * OpenRouter supports video as base64 data URI or public URL.
     */
    private function handleVideoInChat(int $chatId, int $userId, $update, ?string $caption): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_user_not_found'));
            return;
        }

        $db = Database::getInstance();
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $modelId = (int)($extra['model_id'] ?? 0);

        // Check model supports video
        if ($modelId > 0) {
            $model = $db->query("SELECT supported_formats FROM ai_text_models WHERE id = ?", [$modelId])->fetch();
            if ($model && !empty($model['supported_formats'])) {
                $formats = explode(',', strtolower($model['supported_formats']));
                $supportedVideo = array_intersect($formats, ['mp4', 'mpeg', 'mov', 'webm']);
                if (empty($supportedVideo)) {
                    $this->baleClient->sendMessage($chatId, BotTextService::get('chat_video_format_error', ['formats' => $model['supported_formats']]));
                    return;
                }
            }
        }

        $fileId = $update->getVideoFileId();
        $mimeType = $update->getVideoMimeType();

        $caption = trim($caption ?? '');
        if (empty($caption)) {
            $caption = BotTextService::get('chat_video_caption');
        }

        // Download video file
        $rawFileContent = $this->downloadPhoto($fileId);
        if ($rawFileContent === null) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_video_download_error'));
            return;
        }

        // Determine extension from MIME type
        $extMap = [
            'video/mp4' => 'mp4',
            'video/mpeg' => 'mpeg',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
        ];
        $ext = $extMap[$mimeType] ?? 'mp4';

        // Save to temp public directory for URL-based access
        $safeFilename = uniqid('video_', true) . '.' . $ext;
        $localPath = $this->tempDir . $safeFilename;
        file_put_contents($localPath, $rawFileContent);

        // Generate public URL for OpenRouter to fetch directly
        $baseUrl = \Core\Config::get('SITE_BASE_URL', 'https://mobixai.ir');
        $publicUrl = $baseUrl . '/uploads/tmp/' . $safeFilename;

        // Pass as video_url type — buildMessagesFromHistory will handle it
        $this->processChatMessage($chatId, $userId, $caption, $publicUrl, 'video_url', $localPath);
    }

    /**
     * Download a file from Bale API by file_id.
     * Returns null on failure or if file exceeds max_file_size_mb setting.
     */
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

        // Enforce max file size from settings
        try {
            $db = Database::getInstance();
            $row = $db->query("SELECT value FROM settings WHERE key_name = 'max_file_size_mb'")->fetch();
            $maxMb = (int)($row['value'] ?? 20);
            if ($maxMb < 1) $maxMb = 20;
            $maxBytes = $maxMb * 1024 * 1024;
            if (strlen($data) > $maxBytes) {
                \Core\AILogger::error('chat', 'File too large', ['size' => strlen($data), 'max' => $maxBytes]);
                return null;
            }
        } catch (\Throwable $e) {
            // Fallback: 20MB
            if (strlen($data) > 20 * 1024 * 1024) {
                return null;
            }
        }

        return $data;
    }

    private function showConversationHistory(int $chatId, int $userId, int $page = 0): void
    {
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $db = Database::getInstance();
        $limitRow = $db->query("SELECT value FROM settings WHERE key_name = 'chat_history_per_page'")->fetch();
        $perPage = (int)($limitRow['value'] ?? 10);
        if ($perPage < 1) $perPage = 10;

        $offset = $page * $perPage;
        $countRow = $db->query("SELECT COUNT(*) as c FROM chat_conversations WHERE user_id = ?", [$internalId])->fetch();
        $total = (int)($countRow['c'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));

        if ($page >= $totalPages) {
            $page = $totalPages - 1;
            $offset = $page * $perPage;
        }

        $convs = $db->query(
            "SELECT id, model, title, total_input_chars, total_output_chars, total_cost_credits, status, 
                    (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = chat_conversations.id) as msg_count, created_at 
             FROM chat_conversations WHERE user_id = ? ORDER BY updated_at DESC LIMIT ? OFFSET ?",
            [$internalId, $perPage, $offset]
        )->fetchAll();

        if (empty($convs)) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('chat_history_empty'), $this->getChatEntryKeyboard());
            return;
        }

        $msg = BotTextService::get('chat_history_title', ['page' => ($page + 1), 'total' => $totalPages]);
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

        $navRow = [];
        if ($page > 0) {
            $navRow[] = ['text' => '◀️ قبلی', 'callback_data' => 'chat_history_page_' . ($page - 1)];
        }
        $navRow[] = ['text' => "📄 " . ($page + 1) . "/{$totalPages}", 'callback_data' => 'chat_history'];
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
            $stmt = $db->query("SELECT bs.state FROM bot_state bs JOIN users u ON bs.user_id = u.id WHERE u.bale_user_id = ?", [$baleUserId]);
            $row = $stmt->fetch();
            return $row['state'] ?? null;
        } catch (\Throwable $e) { return null; }
    }
}