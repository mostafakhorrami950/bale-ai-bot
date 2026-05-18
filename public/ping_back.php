<?php
/**
 * PING BACK — تست ارسال پیام از سرور به بله (خروجی)
 */
$env = parse_ini_file(__DIR__ . '/../.env');
$token = $env['BALE_BOT_TOKEN'];

$chatId = $_GET['id'] ?? '123456789'; // ID خودتان را وارد کنید

$url = "https://tapi.bale.ai/bot{$token}/sendMessage";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'chat_id' => $chatId,
        'text' => "🔔 تست خروجی از سرور: " . date('Y-m-d H:i:s')
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header('Content-Type: application/json');
echo json_encode([
    'http_code' => $http,
    'bale_response' => json_decode($resp, true),
    'server_time' => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT);