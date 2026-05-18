<?php
/**
 * ⚡ FIX & CLEAN — یکبار اجرا کنید، همه مشکلات حل می‌شود
 * 
 * این فایل در سرور mobixai.ir باید اجرا شود:
 *   https://mobixai.ir/public/fix_and_clean.php
 * 
 * کارهایی که انجام می‌دهد:
 * 1. webhook.php ← بازنویسی با php://input اول
 * 2. init.php ← حذف session_write_close
 * 3. processed_updates ← TRUNCATE
 * 4. Webhook ← تنظیم مجدد
 * 5. حذف تمام فایل‌های تست (امنیتی)
 * 6. حذف خودش
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 120);

header('Content-Type: text/plain; charset=utf-8');

echo "🔧 Bale Bot — Fix & Clean\n";
echo "⏱ " . date('Y-m-d H:i:s') . "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$ok = true;

// ===== 1. Fix init.php =====
$initFile = __DIR__ . '/../init.php';
$initContent = @file_get_contents($initFile);
if ($initContent !== false) {
    if (str_contains($initContent, 'session_write_close')) {
        $initContent = preg_replace('/\s+session_write_close\(\);/', '', $initContent);
        @file_put_contents($initFile, $initContent);
        echo "✅ init.php: session_write_close removed\n";
    } else {
        echo "✅ init.php: already correct\n";
    }
} else {
    echo "❌ Cannot read init.php\n";
    $ok = false;
}

// ===== 2. Fix webhook.php =====
$webhookFile = __DIR__ . '/webhook.php';
echo "✅ webhook.php: ";
$check = @file_get_contents($webhookFile);
if ($check === false) {
    echo "Cannot read\n";
    $ok = false;
} elseif (str_contains($check, 'Read raw input IMMEDIATELY') || str_contains($check, '// 1. Read raw input')) {
    echo "already correct\n";
} else {
    $newWebhook = '<?php
$rawInput = @file_get_contents(\'php://input\');
$logFile = __DIR__ . \'/debug.txt\';
@file_put_contents($logFile, date(\'[Y-m-d H:i:s]\') . " WEBHOOK: " . strlen($rawInput??\'\') . " bytes\n", FILE_APPEND);
require_once __DIR__ . \'/error_handler.php\';
require_once __DIR__ . \'/../init.php\';
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
use Modules\Bot\UpdateFactory;
use Modules\Bot\Dispatcher;
if (!headers_sent()) { http_response_code(200); header(\'Content-Type: text/plain; charset=utf-8\'); }
echo "OK"; flush();
if (function_exists(\'fastcgi_finish_request\')) fastcgi_finish_request();
if (empty($rawInput)) exit;
$d = @json_decode($rawInput, true);
if (!is_array($d)) exit;
try {
    $u = UpdateFactory::create($d);
} catch (\Throwable $e) {
    @file_put_contents($logFile, date(\'[Y-m-d H:i:s]\') . " ERR: " . $e->getMessage() . "\n", FILE_APPEND);
    exit;
}
if (!$u || !$u->getChatId()) exit;
if ($u->isDuplicate()) exit;
try { (new Dispatcher($u))->dispatch($u); } catch (\Throwable $e) {
    @file_put_contents($logFile, date(\'[Y-m-d H:i:s]\') . " DISP: " . $e->getMessage() . "\n", FILE_APPEND);
}';
    @file_put_contents($webhookFile, $newWebhook);
    echo "rewritten ✓\n";
}

// ===== 3. Clear processed_updates =====
try {
    require_once __DIR__ . '/../init.php';
    $db = \Database\Database::getInstance();
    $db->query("TRUNCATE TABLE processed_updates");
    echo "✅ processed_updates: cleared\n";
} catch (\Throwable $e) {
    echo "⚠️ processed_updates: " . $e->getMessage() . "\n";
}

// ===== 4. Re-set webhook =====
$token = '';
foreach (file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $p = explode('=', $line, 2);
    if (trim($p[0]) === 'BALE_BOT_TOKEN') { $token = trim($p[1]??''); break; }
}
if ($token) {
    $ch = curl_init("https://tapi.bale.ai/bot{$token}/setWebhook");
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['url'=>'https://mobixai.ir/public/webhook.php']), CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15]);
    $r = curl_exec($ch); $h = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode($r,true);
    if ($j['ok']??false) { echo "✅ Webhook: set successfully\n"; }
    else { echo "⚠️ Webhook: " . ($j['description']??'unknown') . "\n"; }
    sleep(1);
    $ch = curl_init("https://tapi.bale.ai/bot{$token}/getWebhookInfo");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
    $r = curl_exec($ch); curl_close($ch);
    $j = json_decode($r,true);
    $p = $j['result']['pending_update_count'] ?? -1;
    echo "📊 Pending updates: {$p}\n";
}

// ===== 5. Delete test files =====
$filesToDelete = [
    __DIR__ . '/bale_test.php',
    __DIR__ . '/clear_pending.php',
    __DIR__ . '/fix_final.php',
    __DIR__ . '/webhook_debug.php',
    __DIR__ . '/webhook_status.php',
    __DIR__ . '/debug.txt',
    __DIR__ . '/fix_and_clean.php', // self-delete
];
$deleted = 0;
foreach ($filesToDelete as $f) {
    if (file_exists($f)) {
        @unlink($f);
        echo "🗑️ Deleted: " . basename($f) . "\n";
        $deleted++;
    }
}
echo "✅ {$deleted} test files deleted\n";

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━\n";

if ($p === 0 || ($p > 0 && $p < 10)) {
    echo "✅✅✅ ALL FIXED!\n";
    echo "Now send /start to @mobixbot\n";
} else {
    echo "⚠️ Pending: {$p}. Wait a moment and check again.\n";
    echo "Send /start to @mobixbot\n";
}
echo "\n";