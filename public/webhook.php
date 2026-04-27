<?php

require_once __DIR__ . '/error_handler.php';

// Force reload all cached files by touching Router.php
$routerFile = __DIR__ . '/../src/Modules/Bot/Router.php';
if (file_exists($routerFile)) {
    touch($routerFile);
}
if (function_exists('opcache_reset')) {
    opcache_reset();
}

function debug_log(string $message, array $context = []): void {
    $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    $msg = date('[Y-m-d H:i:s]') . " DEBUG: $message$ctx\n";
    file_put_contents(__DIR__ . '/debug.txt', $msg, FILE_APPEND);
}

require_once __DIR__ . '/../init.php';

use Modules\Bot\UpdateFactory;
use Modules\Bot\Router;
use Modules\Bot\Dispatcher;
use Core\Config;

// Production Hardening: Logging function
function bot_log($level, $message, $context = []) {
    try {
        $db = \Database\Database::getInstance();
        $stmt = $db->prepare("INSERT INTO bot_logs (level, message, context) VALUES (?, ?, ?)");
        $stmt->execute([$level, $message, json_encode($context)]);
    } catch (\Exception $e) {
        error_log("Bot Log Failed: " . $e->getMessage());
    }
}

// 1. Receive raw POST data
$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    bot_log('WARNING', 'Empty webhook request received');
    exit;
}

bot_log('INFO', 'Webhook update received', ['raw' => $rawInput]);

// 2. Parse JSON
$updateData = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    bot_log('ERROR', 'Invalid JSON received', ['error' => json_last_error_msg()]);
    exit;
}

// 3. Create Update object
$update = UpdateFactory::create($updateData);
file_put_contents(__DIR__ . '/debug.txt', date('[Y-m-d H:i:s]') . " RAW DATA: " . json_encode($updateData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
debug_log("Webhook received", ['type' => $update->isMessage() ? 'message' : ($update->isCallback() ? 'callback' : 'other'), 'user_id' => $update->getUserId() ?? 'none']);
if (!$update || !$update->getChatId()) {
    bot_log('ERROR', 'Invalid Update or Missing Chat ID', ['data' => $updateData]);
    exit('Parse error');
}

// 4. Early Callback Answer
if ($update->isCallback() && $callbackId = $update->getCallbackId()) {
    $client = new \Modules\Bot\BaleClient();
    $client->answerCallbackQuery($callbackId);
}

// 5. Check for duplicate updates
if ($update->isDuplicate()) {
    bot_log('INFO', 'Duplicate update ignored', ['update_id' => $update->getId()]);
    exit('Duplicate update');
}

// 6. Route & Dispatch
$text = $update->getText() ?? 'NULL';
file_put_contents(__DIR__ . '/debug.txt', date('[Y-m-d H:i:s]') . " PARSED TEXT: " . $text . "\n", FILE_APPEND);
try {
    $dispatcher = new Dispatcher($update);
    $dispatcher->dispatch($update);
    debug_log("Dispatch completed", ['update_id' => $update->getId() ?? 'unknown']);
    error_log("DEBUG: Dispatch completed. Update ID: " . ($update->getId() ?? 'unknown'));
    
    $update->markAsProcessed();

} catch (\Throwable $e) {
    bot_log('ERROR', 'Dispatch error', [
        'update_id' => $update->getId(),
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(200); // Always return 200 to Bale to avoid retries on logic errors
}
