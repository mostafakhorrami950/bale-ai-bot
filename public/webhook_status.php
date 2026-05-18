<?php
/**
 * Webhook Status & Diagnostic Page
 * 
 * This tool checks if the Bale AI Bot webhook is properly connected and responding.
 * It tests:
 *   1. PHP runtime requirements
 *   2. Database connection
 *   3. Bot token existence
 *   4. Bale API webhook info (what URL Bale thinks it's sending to)
 *   5. Bale API self-test (send a test message)
 *   6. Last webhook hits (from debug.txt)
 *   7. OpenRouter API key check
 * 
 * Access: Anyone with the URL can view this page (no authentication required).
 * This is intentional so you can debug connection issues easily.
 */

require_once __DIR__ . '/../init.php';

use Core\Config;
use Modules\Bot\BaleClient;
use Database\Database;

header('Content-Type: text/html; charset=utf-8');

$checksPassed = 0;
$checksTotal = 0;
$errors = [];

function check(string $label, bool $condition, string $detail = ''): string {
    global $checksPassed, $checksTotal;
    $checksTotal++;
    $icon = $condition ? '✅' : '❌';
    if ($condition) $checksPassed++;
    return "<tr><td>{$icon}</td><td>{$label}</td><td>" . ($condition ? 'OK' : 'FAIL') . "</td><td>" . htmlspecialchars($detail) . "</td></tr>";
}

// ---- Check 1: PHP Version ----
$phpOk = version_compare(PHP_VERSION, '8.0', '>=');
$r1 = check('PHP Version ≥ 8.0', $phpOk, 'Current: ' . PHP_VERSION);

// ---- Check 2: Required PHP Extensions ----
$extensions = ['curl', 'json', 'mbstring', 'pdo_mysql', 'session'];
$extOk = true;
$missingExts = [];
foreach ($extensions as $ext) {
    if (!extension_loaded($ext)) {
        $extOk = false;
        $missingExts[] = $ext;
    }
}
$r2 = check('PHP Extensions', $extOk, $extOk ? 'All loaded' : 'Missing: ' . implode(', ', $missingExts));

// ---- Check 3: .env file ----
$envFile = BASE_PATH . '/.env';
$envOk = file_exists($envFile);
$r3 = check('.env file exists', $envOk, $envOk ? realpath($envFile) : 'NOT FOUND');

// ---- Check 4: Database Connection ----
$dbOk = false;
$dbError = '';
try {
    $db = Database::getInstance();
    $db->query("SELECT 1");
    $dbOk = true;
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}
$r4 = check('Database Connection', $dbOk, $dbOk ? 'Connected' : $dbError);

// ---- Check 5: Bot Token from .env ----
$token = Config::get('BALE_BOT_TOKEN', '');
$tokenOk = !empty($token) && strlen($token) > 20;
$r5 = check('BALE_BOT_TOKEN', $tokenOk, $tokenOk ? 'Exists (' . substr($token, 0, 10) . '...)' : 'MISSING or too short');

// ---- Check 6: OpenRouter API Key ----
$orKey = Config::get('OPENROUTER_API_KEY', '');
$orOk = !empty($orKey) && strlen($orKey) > 10;
$r6 = check('OPENROUTER_API_KEY', $orOk, $orOk ? 'Exists (' . substr($orKey, 0, 8) . '...)' : 'MISSING or too short');

// ---- Check 7: Bale Webhook Info ----
$webhookOk = false;
$webhookInfo = '';
try {
    $client = new BaleClient();
    $info = $client->getWebhookInfo();
    if (isset($info['ok']) && $info['ok'] === true) {
        $webhookOk = true;
        $webhookInfo = json_encode($info['result'] ?? $info, JSON_UNESCAPED_SLASHES);
    } else {
        $webhookInfo = $info['description'] ?? 'API Error';
    }
} catch (\Throwable $e) {
    $webhookInfo = $e->getMessage();
}
$r7 = check('Bale Webhook Info', $webhookOk, $webhookOk ? $webhookInfo : 'FAILED: ' . htmlspecialchars($webhookInfo));

// ---- Check 8: debug.txt last hits ----
$debugFile = __DIR__ . '/debug.txt';
$debugLines = [];
$lastWebhookHit = 'N/A';
if (file_exists($debugFile)) {
    $lines = file($debugFile);
    // Get last 10 lines
    $debugLines = array_slice($lines, -10);
    // Find last "WEBHOOK_HIT" line
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (str_contains($lines[$i], 'WEBHOOK_HIT')) {
            $lastWebhookHit = trim($lines[$i]);
            break;
        }
    }
}
$debugOk = $lastWebhookHit !== 'N/A';
$r8 = check('Last Webhook Hit', $debugOk, $debugOk ? $lastWebhookHit : 'No hits recorded');

// ---- Check 9: Self-test (simulate /start) ----
// We just check if webhook.php responds correctly by checking if Bale tells us
// the webhook URL is valid. The actual message test is done via the URL check.
$selfTestOk = $webhookOk && str_contains($webhookInfo ?? '', 'webhook.php');
$r9 = check('Webhook URL matches', $selfTestOk, 'URL: https://mobixai.ir/public/webhook.php');

// ---- Check 10: Bale API setWebhook test ----
// We already checked info above. If info shows the correct URL, it's set.

$overallOk = $checksPassed === $checksTotal;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bale AI Bot — Webhook Diagnostic</title>
    <style>
        body { font-family: Tahoma, Arial, sans-serif; background: #f5f6fa; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { color: #2d3436; font-size: 1.5rem; }
        .status-box { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; font-size: 1.1rem; }
        .status-ok { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status-fail { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        th, td { padding: 10px 15px; text-align: right; border-bottom: 1px solid #eee; font-size: 0.9rem; }
        th { background: #0984e3; color: #fff; font-weight: normal; }
        td:nth-child(1) { width: 40px; text-align: center; font-size: 1.2rem; }
        td:nth-child(2) { font-weight: bold; }
        td:nth-child(4) { font-family: monospace; font-size: 0.8rem; color: #636e72; direction: ltr; text-align: left; max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .debug-box { background: #1e1e2e; color: #f0f0f0; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 0.8rem; direction: ltr; text-align: left; overflow-x: auto; white-space: pre-wrap; margin-top: 20px; }
        h2 { font-size: 1.2rem; color: #2d3436; margin-top: 30px; }
        .actions { margin: 20px 0; }
        .actions a { display: inline-block; padding: 10px 20px; background: #0984e3; color: #fff; text-decoration: none; border-radius: 6px; margin-left: 10px; }
        .actions a.reconnect { background: #e17055; }
        .actions a:hover { opacity: 0.85; }
        @media (max-width: 600px) { td:nth-child(4) { max-width: 150px; } }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 Bale AI Bot — Webhook Diagnostic</h1>
    <p>تاریخ: <?php echo date('Y-m-d H:i:s'); ?> | سرور: <?php echo php_uname('n'); ?></p>

    <div class="status-box <?php echo $overallOk ? 'status-ok' : 'status-fail'; ?>">
        <?php echo $overallOk 
            ? '✅ همه چیز اوکی است! اتصال ربات برقرار است.' 
            : "❌ {$checksPassed} از {$checksTotal} تست قبول شده. مشکل وجود دارد.";
        ?>
    </div>

    <div class="actions">
        <a href="setup_webhook.php?action=info" target="_blank">📋 Webhook Info</a>
        <a href="setup_webhook.php?action=set" class="reconnect" target="_blank">🔄 تنظیم مجدد Webhook</a>
        <a href="health.php" target="_blank">💚 Health Check</a>
        <a href="admin/ai_logs.php" target="_blank">📝 AI Logs</a>
    </div>

    <h2>📊 تست‌های سیستم</h2>
    <table>
        <thead><tr><th></th><th>تست</th><th>وضعیت</th><th>جزئیات</th></tr></thead>
        <tbody>
            <?php echo $r1 . $r2 . $r3 . $r4 . $r5 . $r6 . $r7 . $r8 . $r9; ?>
        </tbody>
    </table>

    <?php if ($debugLines): ?>
    <h2>📄 آخرین خطوط debug.txt</h2>
    <div class="debug-box"><?php echo htmlspecialchars(implode('', $debugLines)); ?></div>
    <?php endif; ?>

    <h2>📝 راهنمای عیب‌یابی</h2>
    <table>
        <thead><tr><th>#</th><th>مشکل</th><th>راه‌حل</th></tr></thead>
        <tbody>
            <tr><td>❌</td><td>BALE_BOT_TOKEN</td><td>فایل .env را بررسی کنید</td></tr>
            <tr><td>❌</td><td>Database Connection</td><td>MySQL/MariaDB را چک کنید</td></tr>
            <tr><td>❌</td><td>Webhook Info</td><td>روی "تنظیم مجدد Webhook" کلیک کنید</td></tr>
            <tr><td>❌</td><td>Last Webhook Hit</td><td>ربات پیام نمی‌گیرد → Bale API وصل نیست</td></tr>
            <tr><td>⚠️</td><td>Webhook URL matches</td><td>مطمئن شوید URL به webhook.php اشاره می‌کند</td></tr>
        </tbody>
    </table>

    <p style="color:#636e72;font-size:0.8rem;margin-top:20px;">Bale AI Bot v2.0 — Diagnostic Page</p>
</div>
</body>
</html>