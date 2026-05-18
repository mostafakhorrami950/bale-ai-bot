<?php
/**
 * Bale AI Bot — Complete Connection Test
 * SELF-CONTAINED - no init.php or classes needed
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Read .env manually
$env = [];
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        $env[trim($parts[0])] = trim($parts[1] ?? '');
    }
}
$token = $env['BALE_BOT_TOKEN'] ?? '';

function callBale($method, $params = []) {
    global $token;
    $ch = curl_init("https://tapi.bale.ai/bot{$token}/{$method}");
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true];
    if (!empty($params)) {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = json_encode($params);
        $opt[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opt);
    $r = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $j = json_decode($r, true);
    return ['http' => $http, 'ok' => ($j['ok'] ?? false) === true, 'data' => $j, 'err' => $err];
}

header('Content-Type: text/html; charset=utf-8');
$action = $_GET['action'] ?? '';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="UTF-8"><title>🧪 Bale Connection Test</title>
<style>
body{font-family:Tahoma;background:#0d1117;color:#c9d1d9;padding:20px}
.container{max-width:800px;margin:0 auto}
.box{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:15px;margin:15px 0}
h1{color:#58a6ff;font-size:1.4rem}
.pass{color:#3fb950}.fail{color:#f85149}.warn{color:#d29922}
.code{background:#30363d;padding:2px 6px;border-radius:3px;font-size:0.85rem}
pre{background:#0d1117;border:1px solid #30363d;padding:10px;font-size:0.8rem;direction:ltr;text-align:left;overflow:auto;max-height:250px;border-radius:5px}
a.btn{display:inline-block;padding:8px 15px;background:#238636;color:#fff;text-decoration:none;border-radius:5px;margin:3px}
a.danger{background:#da3633}
.row{display:flex;align-items:center;gap:10px;margin:5px 0}
</style>
</head>
<body>
<div class="container">
<h1>🧪 Bale AI Bot — Connection Test</h1>
<p>⏱ <?php echo date('Y-m-d H:i:s'); ?></p>

<?php if (empty($token)): ?>
<div class="box"><h3 class="fail">❌ BALE_BOT_TOKEN not found in .env</h3></div>
<?php exit; endif; ?>

<div class="box"><h3>🔑 Token: <code class="code"><?php echo substr($token,0,15); ?>...</code></h3></div>

<?php
// TEST 1: getMe
$me = callBale('getMe');
$botName = $me['data']['result']['username'] ?? '?';
?>
<div class="box">
<h3>📡 1. getMe (اتصال به API بله)</h3>
<div class="row"><span class="<?php echo $me['ok']?'pass':'fail'; ?>"><?php echo $me['ok']?'✅':'❌'; ?></span> <?php echo $me['ok'] ? "اتصال برقرار - @{$botName}" : 'اتصال برقرار نیست'; ?></div>
<pre><?php echo json_encode($me['data'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); ?></pre>
</div>

<?php
// TEST 2: getWebhookInfo
$info = callBale('getWebhookInfo');
$pending = $info['data']['result']['pending_update_count'] ?? 0;
$webhookUrl = $info['data']['result']['url'] ?? '(none)';
?>
<div class="box">
<h3>🌐 2. Webhook Info</h3>
<div class="row"><span class="<?php echo $info['ok']?'pass':'fail'; ?>"><?php echo $info['ok']?'✅':'❌'; ?></span> URL: <code class="code"><?php echo htmlspecialchars($webhookUrl); ?></code></div>
<div class="row"><span class="<?php echo $pending===0?'pass':'fail'; ?>"><?php echo $pending===0?'✅':($pending<100?'⚠️':'❌'); ?></span> Pending: <code class="code"><?php echo number_format($pending); ?></code> <?php if($pending>0): ?><span class="warn">— باید پاک شود!</span><?php endif; ?></div>
<pre><?php echo json_encode($info['data'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); ?></pre>
</div>

<?php
// TEST 3: Test webhook.php directly
$testPayload = json_encode([
    'update_id' => 777777,
    'message' => ['message_id'=>1,'from'=>['id'=>123456789,'is_bot'=>false,'first_name'=>'Tester'],'chat'=>['id'=>123456789,'type'=>'private'],'date'=>time(),'text'=>'/start']
]);
$wUrl = (isset($_SERVER['HTTPS'])?'https://':'http://').$_SERVER['HTTP_HOST'].'/public/webhook.php';
$ch = curl_init($wUrl);
curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$testPayload, CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_HEADER=>true]);
$r = curl_exec($ch);
$h = curl_getinfo($ch,CURLINFO_HTTP_CODE);
$hs = curl_getinfo($ch,CURLINFO_HEADER_SIZE);
$body = substr($r, $hs);
curl_close($ch);

// Check debug.txt for new entry
$debugFile = __DIR__ . '/debug.txt';
$debugOk = false;
$debugLines = [];
if (file_exists($debugFile)) {
    $all = file($debugFile);
    $debugLines = array_slice($all, -8);
    foreach ($all as $l) { if (str_contains($l,'777777')) { $debugOk = true; break; } }
}
?>
<div class="box">
<h3>🎯 3. Test webhook.php directly</h3>
<div class="row"><span class="<?php echo $h===200?'pass':'fail'; ?>"><?php echo $h===200?'✅':'❌'; ?></span> HTTP <code class="code"><?php echo $h; ?></code> | Response: <code class="code"><?php echo htmlspecialchars($body); ?></code></div>
<div class="row"><span class="<?php echo $debugOk?'pass':'fail'; ?>"><?php echo $debugOk?'✅':'❌'; ?></span> debug.txt: <?php echo $debugOk ? 'updated with 777777 ✅' : 'NOT updated ⚠️'; ?></div>
<?php if ($debugLines): ?><h4 style="color:#8b949e;margin:10px 0 5px;font-size:0.85rem">📄 debug.txt (last 8):</h4><pre><?php echo htmlspecialchars(implode('',$debugLines)); ?></pre><?php endif; ?>
</div>

<?php
// TEST 4: Send test message (if requested)
if ($action === 'send' && !empty($_GET['chat_id'])) {
    $cid = (int)$_GET['chat_id'];
    $snd = callBale('sendMessage', ['chat_id'=>$cid, 'text'=>"🧪 Test from Bale Test Tool\n⏱ ".date('Y-m-d H:i:s')]);
    ?>
    <div class="box">
    <h3>📤 4. Send Test Message</h3>
    <div class="row"><span class="<?php echo $snd['ok']?'pass':'fail'; ?>"><?php echo $snd['ok']?'✅':'❌'; ?></span> <?php echo $snd['ok'] ? 'Message sent successfully!' : 'Send failed'; ?></div>
    <pre><?php echo json_encode($snd['data'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); ?></pre>
    </div>
    <?php
}

// TEST 5: Reset webhook (clears pending)
if ($action === 'reset') {
    $del = callBale('deleteWebhook');
    sleep(1);
    $set = callBale('setWebhook', ['url'=>'https://mobixai.ir/public/webhook.php']);
    sleep(1);
    $chk = callBale('getWebhookInfo');
    $np = $chk['data']['result']['pending_update_count'] ?? 0;
    ?>
    <div class="box">
    <h3>🔄 5. Webhook Reset</h3>
    <div class="row"><span class="<?php echo $del['ok']?'pass':'fail'; ?>"><?php echo $del['ok']?'✅':'❌'; ?></span> Delete: <?php echo $del['ok']?'OK':'FAILED'; ?></div>
    <div class="row"><span class="<?php echo $set['ok']?'pass':'fail'; ?>"><?php echo $set['ok']?'✅':'❌'; ?></span> Set: <?php echo $set['ok']?'OK':'FAILED'; ?></div>
    <div class="row"><span class="<?php echo $np===0?'pass':'fail'; ?>"><?php echo $np===0?'✅':'❌'; ?></span> Pending after reset: <code class="code"><?php echo number_format($np); ?></code>
    <?php if ($np>0): ?><span class="warn">⚠️ Still pending. Try the phpMyAdmin TRUNCATE step.</span><?php endif; ?></div>
    <pre><?php echo json_encode(['delete'=>$del['data'],'set'=>$set['data'],'check'=>$chk['data']], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); ?></pre>
    </div>
    <?php
}

// SUMMARY
$allOk = $me['ok'] && $h === 200 && $debugOk;
?>
<div class="box" style="border-color:<?php echo $allOk?'#3fb950':'#f85149'; ?>;border-width:2px">
<h3><?php echo $allOk ? '✅ ALL TESTS PASSED' : '❌ SOME TESTS FAILED'; ?></h3>
<?php if ($allOk && $pending === 0): ?>
<div style="padding:10px;background:#3fb95020;border:1px solid #3fb950;border-radius:5px;margin-top:10px">
✅ <strong>ارتباط کامل بین Bale API و ربات برقرار است.</strong><br>
ربات @<?php echo $botName; ?> آماده دریافت پیام است.
</div>
<?php endif; ?>
</div>

<div class="box">
<h3>🎮 Actions</h3>
<p>
<a class="btn" href="?action=reset">🔄 Reset Webhook</a>
<a class="btn danger" href="?action=send&chat_id=<?php echo $_GET['chat_id']??''; ?>">📤 Send Test Message</a>
<a class="btn" href="?">🔄 Refresh</a>
</p>
<p style="color:#636e72;font-size:0.85rem">
برای ارسال پیام تست: <code>bale_test.php?action=send&chat_id=ID_USER</code>
</p>
</div>

</div>
</body>
</html>