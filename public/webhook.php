<?php
/**
 * Bale AI Bot — Webhook Entry Point
 * 
 * CRITICAL: php://input MUST be read BEFORE any other code runs.
 */

// 1. Read raw input IMMEDIATELY
$rawInput = @file_get_contents('php://input');
$inputLen = strlen($rawInput ?? '');

// 2. Load error handler & app
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/../init.php';

// 3. Response 200 to Bale (to stop retries)
if (!headers_sent()) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
}
echo "OK";
flush();
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

// 4. Trace hit in debug.txt
$logFile = __DIR__ . '/debug.txt';
if ($inputLen > 0) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " HIT: {$inputLen} bytes\n", FILE_APPEND);
}

if (empty($rawInput)) { exit; }

// 5. Build app components
use Modules\Bot\UpdateFactory;
use Modules\Bot\Dispatcher;

// 6. Process Update
$updateData = @json_decode($rawInput, true);
if (!is_array($updateData)) { exit; }

try {
    $update = UpdateFactory::create($updateData);
    
    // Ignore duplicates
    if ($update->isDuplicate()) { exit; }
    
    // Ignore group/channel messages (bot should not respond in groups/channels)
    if ($update->isGroupOrChannel()) { exit; }
    
    // Dispatch to handlers
    $dispatcher = new Dispatcher($update);
    $dispatcher->dispatch($update);
    
    // Mark as finished
    $update->markAsProcessed();
    
} catch (\Throwable $e) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " FATAL: " . $e->getMessage() . "\n", FILE_APPEND);
}