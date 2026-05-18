<?php
/**
 * ⚡ FLUSH BALE — تخلیه کامل صف پیام‌های بله
 * این فایل پیام‌ها را می‌خواند و تایید می‌کند تا Pending صفر شود.
 */
require_once __DIR__ . '/../init.php';
use Modules\Bot\BaleClient;

header('Content-Type: text/plain; charset=utf-8');
echo "🚀 Starting Bale Queue Flush...\n";

$client = new BaleClient();

// 1. Delete Webhook (Must be deleted to use getUpdates)
echo "1. Deleting Webhook... ";
$del = $client->deleteWebhook();
echo json_encode($del) . "\n";

// 2. Loop getUpdates until empty
$offset = 0;
$total = 0;
echo "2. Fetching updates (Long Polling)...\n";

for ($i = 0; $i < 50; $i++) { // Max 50 batches
    $url = "https://tapi.bale.ai/bot" . \Core\Config::get('BALE_BOT_TOKEN') . "/getUpdates?offset=$offset&limit=100";
    $resp = file_get_contents($url);
    $data = json_decode($resp, true);
    
    if (!$data || !isset($data['result']) || empty($data['result'])) {
        break;
    }
    
    $count = count($data['result']);
    $total += $count;
    $lastUpdate = end($data['result']);
    $offset = $lastUpdate['update_id'] + 1;
    
    echo "   Fetched $count updates (Total: $total). New offset: $offset\n";
    usleep(100000); // 100ms delay
}

echo "3. Final Status:\n";
$info = $client->getWebhookInfo();
echo "   Pending Updates: " . ($info['result']['pending_update_count'] ?? '0') . "\n";

// 4. Re-set Webhook
echo "4. Re-setting Webhook... ";
$set = $client->setWebhook("https://mobixai.ir/public/webhook.php");
echo json_encode($set) . "\n";

if ($total > 0) {
    echo "\n✅ SUCCESS: $total messages cleared from Bale queue.\n";
} else {
    echo "\nℹ️ Queue was already empty or Bale server didn't return messages.\n";
}