<?php
/**
 * Bale AI Bot — Webhook Entry Point (FIXED v2)
 * 
 * CRITICAL: php://input MUST be read BEFORE any other code runs.
 * Some hosting environments close php://input after ini_set or session_start.
 */

// 1. Read raw input IMMEDIATELY — before anything else
$rawInput = @file_get_contents('php://input');
$inputLen = strlen($rawInput ?? '');

// 2. Log to debug.txt right away
$logFile = __DIR__ . '/debug.txt';
@file_put_contents($logFile, date('[Y-m-d H:i:s]') . " WEBHOOK: {$inputLen} bytes\n", FILE_APPEND);

// 3. Load app
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/../init.php';

// 4. Close session immediately
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

use Modules\Bot\UpdateFactory;
use Modules\Bot\Dispatcher;

// 5. HTTP 200 ALWAYS
if (!headers_sent()) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
}
echo "OK";
flush();
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

// 6. If empty input — exit
if (empty($rawInput)) { exit; }

// 7. Parse JSON
$updateData = @json_decode($rawInput, true);
if (!is_array($updateData)) { exit; }

// 8. Create Update object
try {
    $update = UpdateFactory::create($updateData);
} catch (\Throwable $e) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " UPDATE_ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    exit;
}

if (!$update || (!$update->getChatId() && !$update->isPreCheckoutQuery() && !$update->isSuccessfulPayment())) { exit; }

// 9. Ignore channel / non-mention supergroup
$chatType = $updateData['message']['chat']['type'] ?? $updateData['callback_query']['message']['chat']['type'] ?? null;
if ($chatType === 'channel') { exit; }

// 10. Duplicate check
if ($update->isDuplicate()) { exit; }

// 11. Log the chat info for debugging
@file_put_contents($logFile, date('[Y-m-d H:i:s]') . " DISPATCH: chatId=" . $update->getChatId() . " userId=" . $update->getUserId() . " text=" . $update->getText() . "\n", FILE_APPEND);

// 12. Mark as processed (so next time it won't be duplicate)
try { $update->markAsProcessed(); } catch (\Throwable $e) {}

// 13. Dispatch
try {
    $dispatcher = new Dispatcher($update);
    $dispatcher->dispatch($update);
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " DISPATCH: OK\n", FILE_APPEND);
} catch (\Throwable $e) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " DISPATCH: " . $e->getMessage() . "\n", FILE_APPEND);
}