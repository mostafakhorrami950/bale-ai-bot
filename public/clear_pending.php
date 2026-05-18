<?php
/**
 * ⚡ Clear Pending Updates — پاسخ سریع به 5600+ پیام قدیمی
 * 
 * این فایل با متد getUpdates تمام پیام‌های قدیمی را یک‌باره می‌خواند
 * و به هرکدام یک پیام ساده می‌فرستد: 
 * "ربات مجدد راه‌اندازی شد. لطفاً /start را بزنید."
 * 
 * نحوه استفاده:
 *   https://mobixai.ir/public/clear_pending.php
 * 
 * ⚠️ یکبار اجرا کنید و صبر کنید تا تمام شود (ممکن است ۲-۳ دقیقه طول بکشد)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 300); // 5 minutes

// --- Read .env ---
$env = [];
foreach (file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $p = explode('=', $line, 2);
    $env[trim($p[0])] = trim($p[1]??'');
}
$token = $env['BALE_BOT_TOKEN'] ?? '';
if (!$token) { die("❌ Token not found in .env\n"); }

// --- Helper: call Bale API ---
function bale($method, $params=[], $timeout=15) {
    global $token;
    $ch = curl_init("https://tapi.bale.ai/bot{$token}/{$method}");
    $o = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$timeout, CURLOPT_SSL_VERIFYPEER=>true];
    if ($params) { $o[CURLOPT_POST]=true; $o[CURLOPT_POSTFIELDS]=json_encode($params); $o[CURLOPT_HTTPHEADER]=['Content-Type: application/json']; }
    curl_setopt_array($ch, $o);
    $r = curl_exec($ch);
    $h = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $e = curl_error($ch);
    curl_close($ch);
    $j = json_decode($r, true);
    return ['http'=>$h, 'ok'=>($j['ok']??false), 'data'=>$j, 'err'=>$e];
}

header('Content-Type: text/plain; charset=utf-8');

echo "🔧 Bale Bot — Pending Cleaner\n";
echo "⏱ " . date('Y-m-d H:i:s') . "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Step 1: Check current webhook status
$info = bale('getWebhookInfo');
$pending = $info['data']['result']['pending_update_count'] ?? 0;
echo "📊 Current pending: {$pending}\n\n";

if ($pending === 0) {
    echo "✅ No pending updates. Nothing to do.\n";
    exit;
}

// Step 2: Drop webhook temporarily (so we can use getUpdates)
echo "🔄 Removing webhook temporarily...\n";
$del = bale('deleteWebhook');
if (!$del['ok']) { echo "❌ Failed to delete webhook: " . json_encode($del['data']) . "\n"; exit; }
echo "✅ Webhook removed.\n\n";

// Step 3: Get all pending updates via long polling
echo "📥 Fetching pending updates...\n";
$allUpdates = [];
$offset = 0;
$maxIterations = 200; // safety limit
$iter = 0;
$totalFetched = 0;

while ($iter < $maxIterations) {
    $iter++;
    $result = bale('getUpdates', ['offset' => $offset, 'limit' => 100, 'timeout' => 5], 30);
    
    if (!$result['ok']) {
        echo "❌ getUpdates failed: " . json_encode($result['data']) . "\n";
        break;
    }
    
    $updates = $result['data']['result'] ?? [];
    if (empty($updates)) break; // no more updates
    
    $allUpdates = array_merge($allUpdates, $updates);
    $totalFetched += count($updates);
    
    // Update offset for next batch
    $last = end($updates);
    $offset = ($last['update_id'] ?? 0) + 1;
    
    echo "   → Fetched " . count($updates) . " updates (total: {$totalFetched})\n";
    
    // Small delay to prevent rate limiting
    usleep(50000); // 50ms
}

echo "\n✅ Total fetched: {$totalFetched} updates\n\n";

// Step 4: Send simple reply to each unique user
echo "📤 Sending replies...\n";
$sent = 0;
$skipped = 0;
$repliedUsers = []; // avoid sending multiple messages to same user

foreach ($allUpdates as $update) {
    // Extract user info
    $userId = null;
    $chatId = null;
    $firstName = 'کاربر';
    
    if (isset($update['message'])) {
        $userId = $update['message']['from']['id'] ?? null;
        $chatId = $update['message']['chat']['id'] ?? null;
        $firstName = $update['message']['from']['first_name'] ?? 'کاربر';
    } elseif (isset($update['callback_query'])) {
        $userId = $update['callback_query']['from']['id'] ?? null;
        $chatId = $update['callback_query']['message']['chat']['id'] ?? null;
        $firstName = $update['callback_query']['from']['first_name'] ?? 'کاربر';
    } elseif (isset($update['pre_checkout_query'])) {
        $userId = $update['pre_checkout_query']['from']['id'] ?? null;
        $chatId = $update['pre_checkout_query']['from']['id'] ?? null;
    }
    
    if (!$chatId) { $skipped++; continue; }
    
    // Skip if we already sent to this user (avoid spam)
    if (isset($repliedUsers[$chatId])) { $skipped++; continue; }
    $repliedUsers[$chatId] = true;
    
    // Send simple reply
    $reply = bale('sendMessage', [
        'chat_id' => $chatId,
        'text' => "سلام {$firstName} 🙋\n\nربات هوش مصنوعی بله مجدد راه‌اندازی شد!\nلطفاً برای شروع مجدد دستور /start را ارسال کنید.\n\nبابت تأخیر پیش آمده پوزش می‌خواهیم 🙏"
    ]);
    
    if ($reply['ok']) {
        $sent++;
        echo "   ✅ Sent to user {$chatId}\n";
    } else {
        echo "   ❌ Failed for user {$chatId}: {$reply['data']['description']}\n";
    }
    
    // Rate limit: don't send more than 20 messages per second
    usleep(100000); // 100ms = max 10 messages/second
}

echo "\n📊 Results:\n";
echo "   Total updates fetched: {$totalFetched}\n";
echo "   Messages sent: {$sent}\n";
echo "   Skipped (duplicate user): {$skipped}\n\n";

// Step 5: Re-set webhook
echo "🔄 Re-setting webhook...\n";
sleep(1);
$set = bale('setWebhook', ['url' => 'https://mobixai.ir/public/webhook.php']);
if ($set['ok']) {
    echo "✅ Webhook re-set successfully!\n";
} else {
    echo "❌ Failed to re-set webhook: " . json_encode($set['data']) . "\n";
}

// Step 6: Final check
sleep(1);
$check = bale('getWebhookInfo');
$newPending = $check['data']['result']['pending_update_count'] ?? 0;
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📊 Final pending: {$newPending}\n";

if ($newPending > 0) {
    echo "⚠️ Still {$newPending} pending. Run this script again or wait for them to expire.\n";
} else {
    echo "✅✅✅ All pending updates cleared! Bot is ready. 🎉\n";
    echo "➡️ Send /start to @mobixbot to test.\n";
}