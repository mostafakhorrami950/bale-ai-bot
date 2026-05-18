<?php

/**
 * Setup Bale Bot Webhook
 * 
 * Usage:
 *   ?action=set      = set | info | delete | full_reset
 *   ?drop_first  = 1    (to drop pending updates before setting)
 * 
 * Full reset (recommended to clear pending 5609 updates):
 *   ?action=full_reset
 */

require_once __DIR__ . '/../init.php';

use Core\Config;
use Modules\Bot\BaleClient;

header('Content-Type: application/json; charset=utf-8');

$client = new BaleClient();
$fullUrl = 'https://mobixai.ir/public/webhook.php';

$action = $_GET['action'] ?? 'info';

switch ($action) {
    case 'set':
        // Standard setWebhook
        $result = $client->setWebhook($fullUrl);
        break;

    case 'delete':
        // deleteWebhook — drops all pending updates
        $result = $client->deleteWebhook();
        break;

    case 'full_reset':
        // 1. Delete (drops pending queue)
        $step1 = $client->deleteWebhook();
        // 2. Set again
        $step2 = $client->setWebhook($fullUrl);
        
        $result = [
            'step1_delete' => $step1,
            'step2_set'    => $step2
        ];
        break;

    case 'info':
    default:
        $result = $client->getWebhookInfo();
        break;
}

echo json_encode([
    'action'      => $action,
    'target_url'  => $fullUrl,
    'result'      => $result
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);