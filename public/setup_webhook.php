<?php
require_once __DIR__ . '/../init.php';
use Modules\Bot\BaleClient;

header('Content-Type: application/json');
$client = new BaleClient();
$fullUrl = "https://mobixai.ir/public/webhook.php"; 

$res = $client->setWebhook($fullUrl);

echo json_encode([
    'action' => 'set_webhook',
    'target_url' => $fullUrl,
    'bale_response' => $res
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);