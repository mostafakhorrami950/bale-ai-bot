<?php
/**
 * Webhook Status & Diagnostic Page — LIGHT version
 * No PHP classes used, only basic functions to avoid silent crashes.
 */

// 1. Show PHP errors so we can debug
error_reporting(E_ALL);
ini_set('display_errors', 1);

$checks = [];
$pass = 0;
$total = 0;

function ck(string $label, bool $ok, string $detail = ''): array {
    global $pass, $total;
    $total++;
    if ($ok) $pass++;
    return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

// Check 1: PHP version
$checks[] = ck('PHP Version', version_compare(PHP_VERSION, '8.0', '>='), PHP_VERSION);

// Check 2: Extensions
$exts = ['curl', 'json', 'mbstring', 'pdo_mysql', 'session', 'openssl'];
$missing = [];
foreach ($exts as $e) { if (!extension_loaded($e)) $missing[] = $e; }
$checks[] = ck('PHP Extensions', empty($missing), empty($missing) ? 'All OK' : 'Missing: ' . implode(',', $missing));

// Check 3: .env
$envFile = __DIR__ . '/../.env';
$envExists = file_exists($envFile);
$checks[] = ck('.env file', $envExists, $envExists ? realpath($envFile) : 'NOT FOUND');

// Check 4: init.php autoloader works
$autoloadOk = false;
$autoloadMsg = '';
try {
    require_once __DIR__ . '/../init.php';
    $autoloadOk = true;
    $autoloadMsg = 'init.php loaded OK';
} catch (\Throwable $e) {
    $autoloadMsg = 'ERROR: ' . $e->getMessage();
}
$checks[] = ck('init.php', $autoloadOk, $autoloadMsg);

if ($autoloadOk) {
    // Check 5: Config works
    $cfgOk = false;
    $cfgMsg = '';
    try {
        \Core\Config::load($envFile);
        $cfgOk = true;
        $cfgMsg = 'Config loaded';
    } catch (\Throwable $e) {
        $cfgMsg = 'ERROR: ' . $e->getMessage();
    }
    $checks[] = ck('Config', $cfgOk, $cfgMsg);

    // Check 6: Bot token
    $token = getenv('BALE_BOT_TOKEN') ?: (\Core\Config::get('BALE_BOT_TOKEN', ''));
    $tokenOk = !empty($token) && strlen($token) > 20;
    $checks[] = ck('BALE_BOT_TOKEN', $tokenOk, $tokenOk ? 'Exists' : 'MISSING');

    // Check 7: Database
    $dbOk = false;
    $dbMsg = '';
    try {
        $db = \Database\Database::getInstance();
        $db->query("SELECT 1");
        $dbOk = true;
        $dbMsg = 'Connected';
    } catch (\Throwable $e) {
        $dbMsg = 'ERROR: ' . $e->getMessage();
    }
    $checks[] = ck('Database', $dbOk, $dbMsg);

    // Check 8: Bale Webhook Info
    $webhookOk = false;
    $webhookMsg = '';
    try {
        require_once __DIR__ . '/../src/Modules/Bot/BaleClient.php';
        // Manually call Bale API to get webhook info
        $ch = curl_init("https://tapi.bale.ai/bot{$token}/getWebhookInfo");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http === 200) {
            $json = json_decode($resp, true);
            if (isset($json['ok']) && $json['ok'] === true) {
                $webhookOk = true;
                $url = $json['result']['url'] ?? 'none';
                $wc = $json['result']['pending_update_count'] ?? 0;
                $webhookMsg = "URL: {$url} | Pending: {$wc}";
            } else {
                $webhookMsg = 'API error: ' . ($json['description'] ?? 'unknown');
            }
        } else {
            $webhookMsg = "HTTP {$http}";
        }
    } catch (\Throwable $e) {
        $webhookMsg = 'ERROR: ' . $e->getMessage();
    }
    $checks[] = ck('Bale Webhook', $webhookOk, $webhookMsg);

    // Check 9: debug.txt
    $debugFile = __DIR__ . '/debug.txt';
    $debugLast = 'N/A';
    if (file_exists($debugFile)) {
        $lines = file($debugFile);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (strpos($lines[$i], 'WEBHOOK_HIT') !== false) {
                $debugLast = trim($lines[$i]);
                break;
            }
        }
    }
    $checks[] = ck('Last Webhook Hit', $debugLast !== 'N/A', $debugLast);

    // Check 10: OpenRouter key
    $orkey = \Core\Config::get('OPENROUTER_API_KEY', '');
    $checks[] = ck('OPENROUTER_API_KEY', !empty($orkey) && strlen($orkey) > 10, !empty($orkey) ? 'Exists' : 'MISSING');
}

$overall = $pass === $total;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="UTF-8"><title>Bale AI Bot — Webhook Status</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#f5f6fa;padding:20px;margin:0}
.container{max-width:800px;margin:0 auto}
.box{padding:15px;border-radius:8px;margin:15px 0;font-weight:bold}
.box.ok{background:#d4edda;color:#155724}
.box.fail{background:#f8d7da;color:#721c24}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.05)}
th,td{padding:10px;text-align:right;border-bottom:1px solid #eee}
th{background:#0984e3;color:#fff}
td.detail{font-family:monospace;font-size:0.8rem;color:#636e72;direction:ltr;text-align:left;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
a{display:inline-block;padding:8px 15px;background:#0984e3;color:#fff;text-decoration:none;border-radius:6px;margin:3px;font-size:0.9rem}
a.red{background:#e17055}
pre{background:#1e1e2e;color:#f0f0f0;padding:10px;border-radius:5px;font-size:0.8rem;direction:ltr;text-align:left;overflow:auto;margin-top:15px}
.red{color:#e17055}
.green{color:#00b894}
</style>
</head>
<body>
<div class="container">
<h1>🔍 Bale AI Bot — Webhook Status</h1>
<div class="box <?php echo $overall ? 'ok' : 'fail'; ?>">
<?php echo $total ? "{$pass}/{$total} OK" : 'NO CHECKS RAN'; ?>
</div>

<?php if (!$autoloadOk): ?>
<p class="red">⚠️ init.php خطا داد. شاید autoloader مشکل دارد.</p>
<?php endif; ?>

<table>
<tr><th></th><th>تست</th><th>جزئیات</th></tr>
<?php foreach ($checks as $c): ?>
<tr><td><?php echo $c['ok'] ? '✅' : '❌'; ?></td><td><?php echo htmlspecialchars($c['label']); ?></td><td class="detail"><?php echo htmlspecialchars($c['detail']); ?></td></tr>
<?php endforeach; ?>
</table>

<p style="margin-top:20px">
<a href="setup_webhook.php?action=info" target="_blank">📋 Webhook Info</a>
<a href="setup_webhook.php?action=set" class="red" target="_blank">🔄 Set Webhook</a>
<a href="health.php" target="_blank">💚 Health</a>
<a href="admin/ai_logs.php" target="_blank">📝 AI Logs</a>
</p>

<?php
// Show debug.txt content
$debugFile = __DIR__ . '/debug.txt';
if (file_exists($debugFile)) {
    $lines = file($debugFile);
    $last = array_slice($lines, -15);
    echo "<h3>📄 debug.txt (آخرین ۱۵ خط)</h3><pre>" . htmlspecialchars(implode('', $last)) . "</pre>";
}
?>

<p style="color:#636e72;font-size:0.8rem;margin:20px 0">Bale AI Bot — Diagnostics</p>
</div>
</body>
</html>