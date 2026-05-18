<?php
// SUPER MINIMAL WEBHOOK — FOR TRACING
$raw = @file_get_contents('php://input');
$len = strlen($raw ?? '');

// Log to file
@file_put_contents(__DIR__ . '/debug.txt', date('[Y-m-d H:i:s]') . " RAW_HIT: {$len} bytes\n", FILE_APPEND);

// Response 200
http_response_code(200);
echo "OK";

if ($len > 0) {
    // Try to log to DB without any classes
    try {
        $env = parse_ini_file(__DIR__ . '/../.env');
        $db = new PDO("mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8", $env['DB_USER'], $env['DB_PASS']);
        $stmt = $db->prepare("INSERT INTO bot_logs (level, message, context) VALUES ('INFO', 'WEBHOOK_HIT_MINIMAL', ?)");
        $stmt->execute([$raw]);
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/debug.txt', "DB_ERR: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}