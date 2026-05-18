<?php
/**
 * Webhook Debug Tool — Tests Bale API connection directly
 * This file does NOT use any PHP classes, only cURL.
 * It sends a test message to the bot to verify the webhook works.
 */

// Show all errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Read .env manually
$envFile = __DIR__ . '/../.env';
$env = [];
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            list($key, $val) = explode('=', $line, 2);
            $env[trim($key)] = trim($val);
        }
    }
}

$token = $env['BALE_BOT_TOKEN'] ?? '';
$publicUrl = $env['PUBLIC_BASE_URL'] ?? 'https://mobixai.ir';

header('Content-Type: text/html; charset=utf-8');

$action = $_GET['action'] ?? 'status';

echo "<!DOCTYPE html><html lang='fa' dir='rtl'><head><meta charset='UTF-8'><title>Webhook Debug</title>";
echo "<style>body{font-family:Tahoma;background:#f5f6fa;padding:20px}pre{background:#1e1e2e;color:#f0f0f0;padding:10px;border-radius:5px;direction:ltr;text-align:left;overflow:auto}";
echo ".ok{color:green}.fail{color:red}.box{background:#fff;padding:15px;border-radius:8px;margin:10px 0;box-shadow:0 2px 8px rgba(0,0,0,0.05)}</style></head><body>";

echo "<h1>🔧 Webhook Debug Tool</h1>";

if (empty($token)) {
    echo "<p class='fail'>❌ BALE_BOT_TOKEN not found in .env</p></body></html>";
    exit;
}

echo "<div class='box'><strong>Token:</strong> " . substr($token, 0, 10) . "...</div>";

// ====== ACTION: getWebhookInfo ======
if ($action === 'status' || $action === 'info') {
    echo "<h2>📋 getWebhookInfo</h2>";
    $ch = curl_init("https://tapi.bale.ai/bot{$token}/getWebhookInfo");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    echo "<pre>HTTP: {$http}\n";
    if ($err) echo "cURL Error: {$err}\n";
    echo "Response:\n" . json_encode(json_decode($resp, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
}

// ====== ACTION: setWebhook ======
if ($action === 'set') {
    $webhookUrl = $publicUrl . '/public/webhook.php';
    echo "<h2>🔄 setWebhook → {$webhookUrl}</h2>";
    
    $ch = curl_init("https://tapi.bale.ai/bot{$token}/setWebhook");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['url' => $webhookUrl]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    echo "<pre>HTTP: {$http}\n";
    if ($err) echo "cURL Error: {$err}\n";
    echo "Response:\n" . json_encode(json_decode($resp, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
    
    // Now check again
    echo "<h3>📋 Checking after set...</h3>";
    $ch = curl_init("https://tapi.bale.ai/bot{$token}/getWebhookInfo");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $resp = curl_exec($ch);
    curl_close($ch);
    echo "<pre>" . json_encode(json_decode($resp, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
}

// ====== ACTION: deleteWebhook ======
if ($action === 'delete') {
    echo "<h2>🗑️ deleteWebhook</h2>";
    $ch = curl_init("https://tapi.bale.ai/bot{$token}/deleteWebhook");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    echo "<pre>" . json_encode(json_decode($resp, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
}

// ====== ACTION: test_send ======
if ($action === 'test_send') {
    $chatId = $_GET['chat_id'] ?? '';
    if (empty($chatId)) {
        echo "<p class='fail'>❌ Please provide ?chat_id=YOUR_CHAT_ID</p>";
    } else {
        echo "<h2>📤 sendMessage to {$chatId}</h2>";
        $ch = curl_init("https://tapi.bale.ai/bot{$token}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'chat_id' => $chatId,
                'text' => "🔧 Test message from Webhook Debug Tool\nTime: " . date('Y-m-d H:i:s')
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo "<pre>HTTP: {$http}\n" . json_encode(json_decode($resp, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
    }
}

// ====== ACTION: test_webhook_url ======
if ($action === 'test_url') {
    echo "<h2>🌐 Testing webhook.php directly</h2>";
    
    // Simulate a Bale update
    $testPayload = json_encode([
        'update_id' => 999999,
        'message' => [
            'message_id' => 1,
            'from' => ['id' => 123456789, 'is_bot' => false, 'first_name' => 'Test'],
            'chat' => ['id' => 123456789, 'type' => 'private'],
            'date' => time(),
            'text' => '/start'
        ]
    ]);
    
    $ch = curl_init($publicUrl . '/public/webhook.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $testPayload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => true,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);
    curl_close($ch);
    
    echo "<h3>HTTP Status: {$http}</h3>";
    echo "<h4>Response Headers:</h4><pre>" . htmlspecialchars($headers) . "</pre>";
    echo "<h4>Response Body:</h4><pre>" . htmlspecialchars($body) . "</pre>";
    
    // Check debug.txt
    $debugFile = __DIR__ . '/debug.txt';
    if (file_exists($debugFile)) {
        $lines = file($debugFile);
        $last = array_slice($lines, -5);
        echo "<h4>Last 5 lines of debug.txt:</h4><pre>" . htmlspecialchars(implode('', $last)) . "</pre>";
    }
}

// ====== Navigation ======
echo "<hr><p>";
echo "<a href='?action=status' style='display:inline-block;padding:8px 15px;background:#0984e3;color:#fff;text-decoration:none;border-radius:6px;margin:3px'>📋 Status</a> ";
echo "<a href='?action=set' style='display:inline-block;padding:8px 15px;background:#e17055;color:#fff;text-decoration:none;border-radius:6px;margin:3px'>🔄 Set Webhook</a> ";
echo "<a href='?action=delete' style='display:inline-block;padding:8px 15px;background:#d63031;color:#fff;text-decoration:none;border-radius:6px;margin:3px'>🗑️ Delete Webhook</a> ";
echo "<a href='?action=test_url' style='display:inline-block;padding:8px 15px;background:#00b894;color:#fff;text-decoration:none;border-radius:6px;margin:3px'>🌐 Test webhook.php</a> ";
echo "<a href='?action=test_send&chat_id=YOUR_ID' style='display:inline-block;padding:8px 15px;background:#6c5ce7;color:#fff;text-decoration:none;border-radius:6px;margin:3px'>📤 Send Test Message</a>";
echo "</p>";

echo "<p style='color:#636e72;font-size:0.8rem'>Bale AI Bot — Webhook Debug v1.0</p>";
echo "</body></html>";