<?php
/**
 * AJAX: Start Zibal payment from web
 */
require_once __DIR__ . '/../init.php';
requireAuth();

use Modules\Payment\ZibalService;
use Modules\Bot\Models\User;

$input = json_decode(file_get_contents('php://input'), true);
$planId = (int)($input['plan_id'] ?? 0);

if (!$planId) jsonResponse(['error' => 'پلن نامعتبر']);

try {
    $db = Database::getInstance();
    $webUser = getWebUser();
    $botUserId = getBotUserId($webUser['id']);
    if (!$botUserId) jsonResponse(['error' => 'حساب کاربری یافت نشد']);

    $plan = $db->query("SELECT * FROM payment_plans WHERE id = ? AND is_active = 1", [$planId])->fetch();
    if (!$plan) jsonResponse(['error' => 'پلن یافت نشد']);

    $zibal = new ZibalService();
    $amountRial = (int)$plan['price_rial'];
    $orderId = 'WEB-' . $botUserId . '-' . time();
    $description = "خرید پلن {$plan['name']} - کاربر وب #{$botUserId}";

    $result = $zibal->requestPayment($amountRial, $orderId, $description);
    if (empty($result['trackId'])) {
        jsonResponse(['error' => $result['error'] ?? 'خطا در اتصال به درگاه']);
    }

    $trackId = $result['trackId'];
    $db->query(
        "INSERT INTO payments (user_id, track_id, order_id, amount_rial, credits, plan_id, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')",
        [$botUserId, $trackId, $orderId, $amountRial, (int)$plan['credits'], $plan['id']]
    );

    jsonResponse(['url' => "https://gateway.zibal.ir/start/{$trackId}"]);

} catch (\Throwable $e) {
    Logger::error('web start_payment error', ['error' => $e->getMessage()]);
    jsonResponse(['error' => 'خطا در شروع پرداخت'], 500);
}