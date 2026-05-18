<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$env = [];
foreach (file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $p = explode('=', $line, 2);
    $env[trim($p[0])] = trim($p[1]??'');
}
$token = $env['BALE_BOT_TOKEN'] ?? '';

function api($method, $params=[]) {
    global $token;
    $ch = curl_init("https://tapi.bale.ai/bot{$token}/{$method}");
    $o = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true];
    if ($params) { $o[CURLOPT_POST]=true; $o[CURLOPT_POSTFIELDS]=json_encode($params); $o[CURLOPT_HTTPHEADER]=['Content-Type: application/json']; }
    curl_setopt_array($ch, $o);
    $r = curl_exec($ch); $h = curl_getinfo($ch,CURLINFO_HTTP_CODE); $e = curl_error($ch); curl_close($ch);
    $j = json_decode($r,true);
    return ['http'=>$h, 'ok'=>($j['ok']??false)===true, 'data'=>$j, 'err'=>$e];
}

header('Content-Type: text/html; charset=utf-8');
$action = $_GET['action'] ?? '';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="UTF-8"><title>Bale Bot — Final Fix</title>
<style>
body{font-family:Tahoma;background:#0d1117;color:#c9d1d9;padding:20px}
.container{max-width:800px;margin:0 auto}
.box{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:15px;margin:15px 0}
h1{color:#58a6ff;font-size:1.4rem}
.pass{color:#3fb950}.fail{color:#f85149}.warn{color:#d29922}
code{background:#30363d;padding:2px 6px;border-radius:3px;font-size:0.85rem}
pre{background:#0d1117;border:1px solid #30363d;padding:10px;font-size:0.8rem;direction:ltr;overflow:auto;border-radius:5px}
a.btn{display:inline-block;padding:10px 20px;background:#238636;color:#fff;text-decoration:none;border-radius:5px;margin:5px;font-size:1rem}
a.danger{background:#da3633}
.row{display:flex;align-items:center;gap:10px;margin:8px 0}
.big{font-size:1.2rem}
</style>
</head>
<body>
<div class="container">
<h1>🔧 Bale Bot — Webhook Final Fix</h1>
<p>⏱ <?php echo date('Y-m-d H:i:s'); ?></p>

<?php if (!$token): ?><div class="box"><h3 class="fail">❌ No token</h3></div><?php exit; endif; ?>

<?php
// ===== SHOW CURRENT STATUS =====
$me = api('getMe');
$info = api('getWebhookInfo');
$pending = $info['data']['result']['pending_update_count'] ?? 0;
$botName = $me['data']['result']['username'] ?? '?';
?>
<div class="box">
<h3>📊 وضعیت فعلی</h3>
<div class="row"><span class="pass">✅</span> ربات: @<?php echo $botName; ?></div>
<div class="row"><span>🌐</span> URL: <code><?php echo htmlspecialchars($info['data']['result']['url']??'?'); ?></code></div>
<div class="row"><span class="<?php echo $pending===0?'pass':'fail'; ?>"><?php echo $pending===0?'✅':'❌'; ?></span> 
<span class="big">درخواست‌های در صف: <strong><?php echo number_format($pending); ?></strong></span>
<?php if ($pending > 0): ?><span class="warn">⚠️ Bale API اینها را نگه داشته!</span><?php endif; ?>
</div>
</div>

<?php
// ===== ACTION: set with drop_pending_updates =====
if ($action === 'force_reset') {
    echo '<div class="box"><h3>🔄 ارسال setWebhook با drop_pending_updates=true</h3>';
    
    // Use raw cURL to Bale API with drop_pending_updates
    $ch = curl_init("https://tapi.bale.ai/bot{$token}/setWebhook");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'url' => 'https://mobixai.ir/public/webhook.php',
            'drop_pending_updates' => true
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $r = curl_exec($ch);
    $h = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($r, true);
    
    echo '<div class="row"><span class="'.($j['ok']??false?'pass':'fail').'">'.(($j['ok']??false)?'✅':'❌').'</span> Response: <code>'.($j['ok']??false?'true':'false').'</code></div>';
    echo '<pre>'.json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).'</pre>';
    
    // Also TRUNCATE processed_updates via direct DB query (the webhook.php will do it)
    // Actually we need to tell user to do it manually
    
    sleep(2);
    
    // Check again
    $check = api('getWebhookInfo');
    $newPending = $check['data']['result']['pending_update_count'] ?? 0;
    echo '<div class="row"><span class="'.($newPending===0?'pass':'fail').'">'.($newPending===0?'✅':'❌').'</span>';
    echo 'Pending بعد از ریست: <strong>'.number_format($newPending).'</strong>';
    if ($newPending > 0) {
        echo ' <span class="warn">⚠️ Bale API هنوز پاک نکرده. ممکن است چند دقیقه طول بکشد.</span>';
    } else {
        echo ' <span class="pass">✅ صف pending پاک شد!</span>';
    }
    echo '</div>';
    
    echo '</div>';
}

// ===== ACTION: TRUNCATE processed_updates via webhook =====
if ($action === 'truncate_processed') {
    try {
        require_once __DIR__ . '/../init.php';
        $db = \Database\Database::getInstance();
        $db->query("TRUNCATE TABLE processed_updates");
        echo '<div class="box"><h3 class="pass">✅ جدول processed_updates پاک شد (0 رکورد)</h3></div>';
    } catch (\Throwable $e) {
        echo '<div class="box"><h3 class="fail">❌ خطا: '.htmlspecialchars($e->getMessage()).'</h3></div>';
    }
}

// ===== ACTION: test send =====
if ($action === 'send' && !empty($_GET['chat_id'])) {
    $cid = (int)$_GET['chat_id'];
    $s = api('sendMessage', ['chat_id'=>$cid, 'text'=>"🧪 Test from Bale Test Tool\n⏱ ".date('Y-m-d H:i:s')]);
    echo '<div class="box"><h3>📤 '.($s['ok']?'✅ Success':'❌ Failed').'</h3><pre>'.json_encode($s['data'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).'</pre></div>';
}
?>

<div class="box" style="border-color:#58a6ff;border-width:2px">
<h3>🎯 راه‌حل نهایی — باید دقیقاً این مراحل را انجام دهید:</h3>

<p><strong>مرحله ۱:</strong> روی دکمه زیر کلیک کنید تا Bale صف 5623 را پاک کند:</p>
<p style="text-align:center"><a class="btn" href="?action=force_reset">🔄 مرحله ۱: پاک کردن صف pending</a></p>

<p><strong>مرحله ۲:</strong> اگر Pending هنوز صفر نشد، روی این دکمه کلیک کنید:</p>
<p style="text-align:center"><a class="btn danger" href="?action=truncate_processed">🗑️ مرحله ۲: پاک کردن processed_updates</a></p>

<p><strong>مرحله ۳:</strong> یک پیام <code>/start</code> به ربات @<?php echo $botName; ?> بفرستید</p>

<p><strong>مرحله ۴:</strong> وضعیت را مجدداً بررسی کنید:</p>
<p style="text-align:center"><a class="btn" href="?">🔄 رفرش وضعیت</a></p>
</div>

<div class="box">
<p style="font-size:0.85rem;color:#636e72">
<strong>نکته مهم:</strong> ممکن است Bale API تا چند دقیقه بعد از setWebhook با drop_pending_updates=true، صف را پاک کند.
اگر بعد از ۵ دقیقه هنوز Pending > 0 است، Bale مشکل دارد و باید با پشتیبانی بله تماس بگیرید.
</p>
</div>

</div>
</body>
</html>