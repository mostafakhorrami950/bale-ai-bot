<?php
/**
 * AJAX: Get chat conversation history
 */
require_once __DIR__ . '/../init.php';
requireAuth();

$convId = (int)($_GET['conv'] ?? 0);
if (!$convId) {
    jsonResponse(['messages' => []]);
}

try {
    $db = Database::getInstance();
    $webUser = getWebUser();
    $botUserId = getBotUserId($webUser['id']);

    // Verify ownership
    $conv = $db->query("SELECT * FROM chat_conversations WHERE id = ? AND user_id = ?", [$convId, $botUserId])->fetch();
    if (!$conv) {
        jsonResponse(['messages' => []]);
    }

    $messages = $db->query(
        "SELECT role, content FROM chat_messages WHERE conversation_id = ? ORDER BY id ASC",
        [$convId]
    )->fetchAll();

    jsonResponse(['messages' => $messages]);

} catch (\Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}