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
    
    // Log critical errors (like integrity constraint violations) to app_errors table
    $errMsg = $e->getMessage();
    $baleUserId = null;
    try {
        if (isset($update) && method_exists($update, 'getUserId')) {
            $baleUserId = $update->getUserId();
        }
    } catch (\Throwable $ignored) {}
    
    try {
        $db2 = \Database\Database::getInstance();
        $db2->query(
            "INSERT INTO app_errors (error_type, error_message, error_trace, bale_user_id, payload_data) VALUES (?, ?, ?, ?, ?)",
            [
                'webhook_fatal',
                $errMsg,
                $e->getTraceAsString(),
                $baleUserId,
                mb_substr($rawInput ?? '', 0, 2000)
            ]
        );
    } catch (\Throwable $ignored) {}
    
    // Tell user to use /start if we have a chat_id
    if ($baleUserId) {
        try {
            $bale = new \Modules\Bot\BaleClient();
            $bale->sendMessage($baleUserId, "⚠️ خطایی در پردازش پیام شما رخ داد.\nبرای رفع مشکل، لطفاً دستور /start را ارسال کنید.");
        } catch (\Throwable $ignored) {}
    }
}
