<?php
/**
 * ⚡ FIX FINAL — یکبار اجرا کنید و پاک کنید
 * 
 * این فایل:
 * 1. webhook.php را با نسخه درست بازنویسی می‌کند (php://input اول خوانده شود)
 * 2. init.php را اصلاح می‌کند (حذف session_write_close)
 * 3. processed_updates را خالی می‌کند
 * 4. Webhook را مجدداً تنظیم می‌کند
 * 5. خودش را پاک می‌کند
 * 
 * نحوه استفاده:
 *   https://mobixai.ir/public/fix_final.php
 *   بعد از اتمام، خود به خود پاک می‌شود
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 120);

header('Content-Type: text/plain; charset=utf-8');

echo "🔧 Bale Bot — Final Fix\n";
echo "⏱ " . date('Y-m-d H:i:s') . "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// ===== STEP 1: Fix init.php =====
$initFile = __DIR__ . '/../init.php';
$initContent = file_get_contents($initFile);
$hasWriteClose = str_contains($initContent, 'session_write_close');

if ($hasWriteClose) {
    $fixed = str_replace("\n// Close session\nif (session_status() === PHP_SESSION_ACTIVE) {\n    session_write_close();\n}", '', $initContent);
    $fixed = str_replace("    session_write_close();\n", '', $fixed);
    file_put_contents($initFile, $fixed);
    echo "✅ init.php: session_write_close() removed\n";
} else {
    echo "✅ init.php: already correct\n";
}

// ===== STEP 2: Fix webhook.php =====
$webhookFile = __DIR__ . '/webhook.php';
$currentContent = file_get_contents($webhookFile);
$hasCorrectOrder = str_contains($currentContent, 'Read raw input IMMEDIATELY');

if (!$hasCorrectOrder) {
    $newWebhook = '<?php

/**
 * Bale AI Bot — Webhook Entry Point
 * CRITICAL: php://input MUST be read BEFORE any other code runs.
 */

$rawInput = @file_get_contents(\'php://input\');
$inputLen = strlen($rawInput ?? \'\');

$logFile = __DIR__ . \'/debug.txt\';
@file_put_contents($logFile, date(\'[Y-m-d H:i:s]\') . " WEBHOOK: {$inputLen} bytes\n", FILE_APPEND);

require_once __DIR__ . \'/error_handler.php\';
require_once __DIR__ . \'/../init.php\';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

use Modules\Bot\UpdateFactory;
use Modules\Bot\Dispatcher;

if (!headers_sent()) {
    http_response_code(200);
    header(\'Content-Type: text/plain; charset=utf-8\');
}
echo "OK";
flush();
if (function_exists(\'fastcgi_finish_request\')) { fastcgi_finish_request(); }

if (empty($rawInput)) { exit; }

$updateData = @json_decode($rawInput, true);
if (!is_array($updateData)) { exit; }

try {
    $update = UpdateFactory::create($updateData);
} catch (\\Throwable $e) {
    @file_put_contents($logFile, date(\'[Y-m-d H:i:s]\') . " UPDATE_ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    exit;
}

if (!$update || (!$update->getChatId() && !$update->isPreCheckoutQuery() && !$update->isSuccessfulPayment())) { exit; }

$chatType = $updateData[\'message\'][\'chat\'][\'type\'] ?? $updateData[\'callback_query\'][\'message\'][\'chat\'][\'type\'] ?? null;
if ($chatType === \'channel\') { exit; }

if ($update->isDuplicate()) { exit; }

try {
    $dispatcher = new Dispatcher($update);
    $dispatcher->dispatch($update);
} catch (\\Throwable $e) {
    @file_put_contents($logFile, date(\'[Y-m-d H:i:s]\') . " DISPATCH: " . $e->getMessage() . "\n", FILE_APPEND);
}
';
    file_put_contents($webhookFile, $newWebhook);
    echo "✅ webhook.php: rewritten with correct order\n";
} else {
    echo "✅ webhook.php: already correct\n";
}

// ===== STEP 3: Clear processed_updates =====
try {
    require_once __DIR__ . '/../init.php';
    $db = \Database\Database::getInstance();
    $db->query("TRUNCATE TABLE processed_updates");
    echo "✅ processed_updates table cleared\n";
} catch (\Throwable $e) {
    echo "⚠️ processed_updates truncate failed: " . $e->getMessage() . "\n";
}

// ===== STEP 4: Re-set webhook with drop_pending =====
$token = '';
foreach (file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $p = explode('=', $line, 2);
    if (trim($p[0]) === 'BALE_BOT_TOKEN') { $token = trim($p[1]??''); break; }
}

if ($token) {
    $ch = curl_init("https://tapi.bale.ai/bot{$token}/setWebhook");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['url' => 'https://mobixai.ir/public/webhook.php']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $r = curl_exec($ch);
    $h = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($r, true);
    if ($j['ok'] ?? false) {
        echo "✅ Webhook re-set successfully\n";
    } else {
        echo "⚠️ Webhook set returned: " . ($j['description'] ?? 'unknown') . "\n";
    }
    
    sleep(1);
    $ch = curl_init("https://tapi.bale.ai/bot{$token}/getWebhookInfo");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $r = curl_exec($ch);
    curl_close($ch);
    $j = json_decode($r, true);
    $pending = $j['result']['pending_update_count'] ?? -1;
    echo "📊 Pending after fix: {$pending}\n";
} else {
    echo "⚠️ Token not found in .env\n";
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━\n";

if ($pending === 0) {
    echo "✅✅✅ ALL FIXED! Bot is ready.\n";
    echo "Send /start to @mobixbot now.\n";
} elseif ($pending > 0 && $pending < 10) {
    echo "✅ Almost done. {$pending} pending — will clear automatically.\n";
    echo "Send /start to @mobixbot now.\n";
} else {
    echo "⚠️ Still {$pending} pending. Run this script again.\n";
}

echo "\n";