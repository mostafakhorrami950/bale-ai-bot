<?php

require_once __DIR__ . '/../init.php';

use Core\Config;
use Database\Database;

header('Content-Type: application/json');

$health = [
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'env' => Config::get('APP_ENV'),
    'debug' => Config::get('APP_DEBUG'),
    'checks' => []
];

// Check Database
try {
    $db = Database::getInstance();
    $db->query("SELECT 1");
    $health['checks']['database'] = 'connected';
} catch (\Exception $e) {
    $health['status'] = 'error';
    $health['checks']['database'] = 'failed: ' . $e->getMessage();
}

// Check Bot Token
$token = Config::get('BALE_BOT_TOKEN');
$health['checks']['bot_token'] = !empty($token) ? 'exists' : 'missing';

// Check Webhook URL
$baseUrl = Config::get('PUBLIC_BASE_URL');
$webhookPath = Config::get('BALE_WEBHOOK_PATH');
$health['checks']['public_url'] = $baseUrl . $webhookPath;

if ($health['status'] !== 'ok') {
    http_response_code(500);
}

echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);