<?php
/**
 * PING BACK v2 — تست واقعی با استفاده از کدهای خود ربات
 */
require_once __DIR__ . '/../init.php';
use Modules\Bot\BaleClient;

$chatId = $_GET['id'] ?? null;

if (!$chatId) {
    header('Content-Type: text/plain; charset=utf-8');
    die("لطفاً آیدی عددی خود را در انتهای آدرس وارد کنید. مثال:\n?id=123456789");
}

$client = new BaleClient();
// ارسال پیام با استفاده از متد اصلی ربات
$result = $client->sendMessage((int)$chatId, "✅ تست نهایی از مبدأ سرور\n\nاگر این پیام را در بله دریافت کردید، یعنی کدهای اصلی ربات (BaleClient) کاملاً سالم هستند و به درستی با API بله ارتباط برقرار می‌کنند.\n\n⏱ زمان: " . date('H:i:s'));

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'is_success' => ($result !== false),
    'message_id' => $result,
    'error_from_bale' => $client->getLastError(),
    'note' => 'If is_success is true, the outgoing connection is OK.'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);