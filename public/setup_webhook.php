<?php

require_once __DIR__ . '/../init.php';

use Core\Config;
use Modules\Bot\BaleClient;

header('Content-Type: application/json');

// Security: Basic check to prevent accidental usage in production if not explicitly allowed
if (Config::get('APP_ENV') === 'production' && !isset($_GET['force'])) {
    die(json_encode(['ok' => false, 'error' => 'Use ?force=1 to run in production']));
}

$client = new BaleClient();
$baseUrl = Config::get('PUBLIC_BASE_URL');
$webhookPath = Config::get('BALE_WEBHOOK_PATH');
$fullUrl = $baseUrl . $webhookPath;

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