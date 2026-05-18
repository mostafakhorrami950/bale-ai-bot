<?php
/**
 * Set webhook back to main webhook.php
 */
require_once __DIR__ . '/../init.php';
use Modules\Bot\BaleClient;

$client = new BaleClient();
$url = "https://mobixai.ir/public/webhook.php";
$res = $client->setWebhook($url);

header('Content-Type: application/json');
echo json_encode([
    'action' => 'set_main_webhook',
    'url' => $url,
    'result' => $res
], JSON_PRETTY_PRINT);