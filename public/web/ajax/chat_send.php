<?php
/**
 * AJAX: Send chat message to AI and get response
 * Mirrors bot's ChatService + CreditService logic
 */
require_once __DIR__ . '/../init.php';
requireAuth();

use Modules\AI\ChatService;
use Modules\Bot\CreditService;

$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$modelName = trim($input['model'] ?? 'google/gemini-2.5-flash-image');
$convId = (int)($input['conversation_id'] ?? 0);

if (!$message) {
    jsonResponse(['error' => 'متن پیام نمی‌تواند خالی باشد']);
}

try {
    $db = Database::getInstance();
    $webUser = getWebUser();
    $botUserId = getBotUserId($webUser['id']);

    if (!$botUserId) {
        jsonResponse(['error' => 'حساب کاربری یافت نشد']);
    }

    // Get model data
    $modelData = $db->query("SELECT * FROM ai_text_models WHERE name = ? AND is_active = 1", [$modelName])->fetch();
    if (!$modelData) {
        $modelData = $db->query("SELECT * FROM ai_text_models WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch();
        if (!$modelData) {
            jsonResponse(['error' => 'مدلی یافت نشد']);
        }
        $modelName = $modelData['name'];
    }

    $costPerInput = (float)($modelData['cost_per_input_char'] ?? 0.000001);
    $costPerOutput = (float)($modelData['cost_per_output_char'] ?? 0.000002);

    // Create conversation if new
    if (!$convId) {
        $db->query(
            "INSERT INTO chat_conversations (user_id, model, title) VALUES (?, ?, LEFT(?, 100))",
            [$botUserId, $modelName, $message]
        );
        $convId = (int)$db->lastInsertId();
    }

    // Load conversation history
    $history = $db->query(
        "SELECT * FROM chat_messages WHERE conversation_id = ? ORDER BY id ASC",
        [$convId]
    )->fetchAll();

    // Build messages for API
    $messages = ChatService::buildMessagesFromHistory($history, $message);

    // Calculate input chars cost
    $inputChars = mb_strlen($message);
    $inputCost = ChatService::calcCreditCost($inputChars, $costPerInput);

    // Check balance
    if (!CreditService::hasEnoughCredit($botUserId, $inputCost)) {
        jsonResponse(['error' => 'اعتبار کافی نیست. لطفاً اعتبار خریداری کنید.']);
    }

    // Call AI
    $chatService = new ChatService();
    $result = $chatService->chat($messages, $modelName, $modelData);

    if (isset($result['error']) && $result['error']) {
        jsonResponse(['error' => $result['error']]);
    }

    $responseText = $result['response'] ?? '';
    $outputChars = $result['output_chars'] ?? mb_strlen($responseText);
    $outputCost = ChatService::calcCreditCost($outputChars, $costPerOutput);
    $totalCost = $inputCost + $outputCost;
    $costUsd = $result['cost_usd'] ?? 0;
    $inputTokens = $result['input_tokens'] ?? 0;
    $outputTokens = $result['output_tokens'] ?? 0;

    // Deduct credits
    $referenceId = 'web_chat_' . $convId . '_' . time();
    $deducted = CreditService::deduct($botUserId, $totalCost, $referenceId);

    // Save messages
    $db->query(
        "INSERT INTO chat_messages (conversation_id, role, content, input_chars, output_chars, cost_input_credits, cost_output_credits, model_name, actual_cost_usd, input_tokens, output_tokens) VALUES (?, 'user', ?, ?, 0, ?, 0, ?, ?, ?, ?)",
        [$convId, $message, $inputChars, $inputCost, $modelName, $costUsd, $inputTokens, $outputTokens]
    );
    $db->query(
        "INSERT INTO chat_messages (conversation_id, role, content, input_chars, output_chars, cost_input_credits, cost_output_credits, model_name, actual_cost_usd, input_tokens, output_tokens) VALUES (?, 'assistant', ?, 0, ?, 0, ?, ?, ?, ?, ?)",
        [$convId, $responseText, $outputChars, $outputCost, $modelName, $costUsd, $inputTokens, $outputTokens]
    );

    // Update conversation
    $db->query(
        "UPDATE chat_conversations SET total_input_chars = total_input_chars + ?, total_output_chars = total_output_chars + ?, total_cost_credits = total_cost_credits + ?, updated_at = NOW() WHERE id = ?",
        [$inputChars, $outputChars, $totalCost, $convId]
    );

    // Log AI request
    try {
        $db->query(
            "INSERT INTO ai_requests (user_id, model_id, model_name, prompt, image_type, status, reference_id, actual_cost_usd, input_chars, output_chars, cost_charged) VALUES (?, ?, ?, ?, 'chat', 'success', ?, ?, ?, ?, ?)",
            [$botUserId, $modelData['id'], $modelName, $message, 'web_chat_' . $convId . '_' . time(), $costUsd, $inputChars, $outputChars, $totalCost]
        );
    } catch (\Throwable $ignored) {}

    jsonResponse([
        'response' => $responseText,
        'conversation_id' => $convId,
    ]);

} catch (\Throwable $e) {
    Logger::error('web chat_send error', ['error' => $e->getMessage()]);
    jsonResponse(['error' => 'خطا در پردازش پیام'], 500);
}