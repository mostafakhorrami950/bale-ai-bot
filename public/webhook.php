<?php

require_once __DIR__ . '/error_handler.php';

$routerFile = __DIR__ . '/../src/Modules/Bot/Router.php';
if (file_exists($routerFile)) @touch($routerFile);
if (function_exists('opcache_reset')) @opcache_reset();

require_once __DIR__ . '/../init.php';

use Modules\Bot\UpdateFactory;
use Modules\Bot\Dispatcher;

$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    if (!headers_sent()) { http_response_code(200); echo "OK"; }
    exit;
}

if (!headers_sent()) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Connection: close');
    header('Content-Length: 2');
    echo "OK";
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

function bot_log($level, $message, $context = array()) {
    try {
        $db = \Database\Database::getInstance();
        $db->query("INSERT INTO bot_logs (level, message, context) VALUES (?, ?, ?)",
            array((string)$level, (string)$message, json_encode($context, JSON_UNESCAPED_UNICODE)));
    } catch (\Throwable $e) {}
}

$updateData = @json_decode($rawInput, true);
if (!is_array($updateData)) { exit; }

try {
    $update = UpdateFactory::create($updateData);
} catch (\Throwable $e) { exit; }

$isPaymentQuery = $update->isPreCheckoutQuery();
$isPaymentSuccess = $update->isSuccessfulPayment();

if (!$update || (!$update->getChatId() && !$isPaymentQuery && !$isPaymentSuccess)) { exit; }

$chatType = $updateData['message']['chat']['type'] ?? $updateData['callback_query']['message']['chat']['type'] ?? null;
if ($chatType === 'channel') { exit; }
if ($chatType === 'supergroup' && $update->isMessage()) {
    $text = $update->getText() ?? '';
    $isMentioned = str_contains($text, '@' . ($_ENV['BOT_USERNAME'] ?? 'bot')) || str_starts_with($text, '/') || !empty($updateData['message']['entities']);
    if (!$isMentioned) { exit; }
}

$baleUserId = $update->getUserId();
if ($baleUserId) {
    try {
        $db = \Database\Database::getInstance();
        $db->query("UPDATE users SET last_active_at = CURRENT_TIMESTAMP WHERE bale_user_id = ?", array($baleUserId));
    } catch (\Throwable $e) {}
}

if ($update->isCallback() && $update->getCallbackId()) {
    try {
        $cli = new \Modules\Bot\BaleClient();
        $cli->answerCallbackQuery($update->getCallbackId());
    } catch (\Throwable $e) {}
}

if ($update->isPreCheckoutQuery()) {
    try {
        $data = $update->getPreCheckoutQuery();
        $qid = $update->getPreCheckoutQueryId();
        $payload = $data['invoice_payload'] ?? '';
        $total = $data['total_amount'] ?? 0;
        $cli = new \Modules\Bot\BaleClient();
        if (preg_match('/^plan_(\d+)_user_(\d+)$/', $payload, $m)) {
            $planId = (int)$m[1];
            $db = \Database\Database::getInstance();
            $plan = $db->query("SELECT * FROM payment_plans WHERE id = ? AND is_active = 1", array($planId))->fetch();
            if ($plan && (int)$plan['price_rial'] === $total) {
                $cli->answerPreCheckoutQuery($qid, true);
                exit;
            }
        }
        $cli->answerPreCheckoutQuery($qid, false, 'خطا');
    } catch (\Throwable $e) {}
    exit;
}

if ($update->isSuccessfulPayment()) {
    try {
        $payment = $update->getSuccessfulPayment();
        $payload = $payment['invoice_payload'] ?? '';
        $chargeId = $payment['telegram_payment_charge_id'] ?? '';
        $providerChargeId = $payment['provider_payment_charge_id'] ?? '';
        if (preg_match('/^plan_(\d+)_user_(\d+)$/', $payload, $m)) {
            $planId = (int)$m[1];
            $baleUserId = (int)$m[2];
            $db = \Database\Database::getInstance();
            $plan = $db->query("SELECT * FROM payment_plans WHERE id = ? AND is_active = 1", array($planId))->fetch();
            $user = $db->query("SELECT id FROM users WHERE bale_user_id = ?", array($baleUserId))->fetch();
            if ($plan && $user) {
                $uid = (int)$user['id'];
                $credits = (int)$plan['credits'];
                $price = (int)$plan['price_rial'];
                $trackId = 'bale_' . $chargeId . '_' . time();
                $existing = $db->query("SELECT id FROM payments WHERE track_id = ?", array($trackId))->fetch();
                if (!$existing) {
                    $cli = new \Modules\Bot\BaleClient();
                    $db->query("INSERT INTO payments (user_id, track_id, order_id, amount_rial, credits, plan_id, status, ref_number, verified_at) VALUES (?, ?, ?, ?, ?, ?, 'verified', ?, NOW())", array($uid, $trackId, 'BALE-' . $uid . '-' . time(), $price, $credits, $planId, $providerChargeId));
                    \Modules\Bot\CreditService::addCredits($uid, $credits, 'bale_pay_' . $trackId);
                    $cli->sendMessage($baleUserId, "پرداخت با موفقیت انجام شد!\n" . $credits . " اعتبار به حساب شما اضافه شد.");
                }
            }
        }
    } catch (\Throwable $e) {}
    exit;
}

if ($update->isDuplicate()) { exit; }

try {
    $dispatcher = new Dispatcher($update);
    $dispatcher->dispatch($update);
} catch (\Throwable $e) {}