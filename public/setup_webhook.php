<?php

require_once __DIR__ . '/../init.php';

use Core\Config;
use Modules\Bot\BaleClient;

header('Content-Type: application/json');

$client = new BaleClient();
// Always use the hardcoded public URL + /public/webhook.php
// to avoid .env misconfiguration issues
$fullUrl = 'https://mobixai.ir/public/webhook.php';

$action = $_GET['action'] ?? 'set';

switch ($action) {
    case 'set':
        $response = $client->setWebhook($fullUrl);
        break;
    case 'delete':
        $response = $client->deleteWebhook();
        break;
    case 'info':
    default:
        $response = $client->getWebhookInfo();
        break;
}

echo json_encode([
    'action' => $action,
    'target_url' => $fullUrl,
    'bale_response' => $response
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);