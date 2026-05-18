<?php
require_once __DIR__ . '/../init.php';
use Modules\Bot\BaleClient;

header('Content-Type: application/json');
$client = new BaleClient();

// تست وب‌هوک روی ریشه سایت
$newUrl = "https://mobixai.ir/webhook.php"; 

$res = $client->setWebhook($newUrl);

echo json_encode([
    'message' => 'Webhook target changed to root',
    'new_url' => $newUrl,
    'bale_response' => $res
], JSON_PRETTY_PRINT);