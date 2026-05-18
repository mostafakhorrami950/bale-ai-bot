<?php
/**
 * Bale AI Bot — Webhook Entry Point (DIAGNOSTIC v3)
 * 
 * CRITICAL: php://input MUST be read BEFORE any other code runs.
 */

// 1. Read raw input immediately
$rawInput = @file_get_contents('php://input');
$inputLen = strlen($rawInput ?? '');

// 2. Log everything
$logFile = __DIR__ . '/debug.txt';
@file_put_contents($logFile, date('[Y-m-d H:i:s]') . " STEP1: inputLen={$inputLen} SERVER={$_SERVER['REQUEST_METHOD']} CT={$_SERVER['CONTENT_TYPE']}\n", FILE_APPEND);

// 3. Load app
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/../init.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

use Modules\Bot\UpdateFactory;
use Modules\Bot\Dispatcher;

// 4. HTTP 200 always
if (!headers_sent()) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
}
echo "OK";
flush();
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

// 5. Empty input = health ping
if (empty($rawInput)) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " STEP2: empty input, exit\n", FILE_APPEND);
    exit;
}

// 6. Parse JSON
$updateData = @json_decode($rawInput, true);
if (!is_array($updateData)) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " STEP3: invalid JSON\n", FILE_APPEND);
    exit;
}

@file_put_contents($logFile, date('[Y-m-d H:i:s]') . " STEP3: JSON ok update_id=" . ($updateData['update_id']??'none') . " text=" . ($updateData['message']['text']??'none') . "\n", FILE_APPEND);

// 7. Create Update
try {
    $update = UpdateFactory::create($updateData);
} catch (\Throwable $e) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " STEP4: UpdateFactory failed: " . $e->getMessage() . "\n", FILE_APPEND);
    exit;
}
@file_put_contents($logFile, date('[Y-m-d H:i:s]') . " STEP4: Update created chatId=" . $update->getChatId() . " userId=" . $update->getUserId() . " text=" . $update->getText() . "\n", FILE_APPEND);

$isPaymentQuery = $update->isPreCheckoutQuery();
$isPaymentSuccess = $update->isSuccessfulPayment();

if (!$update || (!$update->getChatId() && !$isPaymentQuery && !$isPaymentSuccess)) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " STEP5: no valid update\n", FILE_APPEND);
    exit;
}

// 8. Channel check
$chatType = $updateData['message']['chat']['type'] ?? $updateData['callback_query']['message']['chat']['type'] ?? null;
if ($chatType === 'channel') { @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " STEP6: channel msg, exit\n", FILE_APPEND); exit; }

// 9. Duplicate check
if ($update->isDuplicate()) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " STEP7: duplicate, exit\n", FILE_APPEND);
    exit;
}
@file_put_contents($logFile, date('[Y-m-d H:i:s]') . " STEP7: not duplicate\n", FILE_APPEND);

// 10. Mark processed
try { $update->markAsProcessed(); } catch (\Throwable $e) {}

// 11. Dispatch
@file_put_contents($logFile, date('[Y-m-d H:i:s]') . " STEP8: dispatching...\n", FILE_APPEND);
try {
    $dispatcher = new Dispatcher($update);
    $dispatcher->dispatch($update);
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " STEP9: dispatch OK\n", FILE_APPEND);
} catch (\Throwable $e) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " STEP9: dispatch FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
}