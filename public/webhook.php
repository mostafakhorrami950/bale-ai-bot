<?php

// 1. Load error handler FIRST
require_once __DIR__ . '/error_handler.php';

// 2. Load app
require_once __DIR__ . '/../init.php';

use Modules\Bot\UpdateFactory;
use Modules\Bot\Dispatcher;

// 3. Read input — MUST be done first before any output
$rawInput = @file_get_contents('php://input');
$inputLen = strlen($rawInput ?? '');

// 4. Log immediately to debug.txt
$logFile = __DIR__ . '/debug.txt';
@file_put_contents($logFile, date('[Y-m-d H:i:s]') . " WEBHOOK_HIT: {$inputLen} bytes | SERVER_METHOD: {$_SERVER['REQUEST_METHOD']} | CONTENT_TYPE: {$_SERVER['CONTENT_TYPE']}\n", FILE_APPEND);

// 5. HTTP 200 ALWAYS
if (!headers_sent()) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
}
echo "OK";

if (empty($rawInput)) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " EMPTY INPUT — probably Bale health ping\n", FILE_APPEND);
    flush();
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    exit;
}

// Flush and continue
flush();
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

// 6. Silent logger
function bot_log($level, $message, $context = array()) {
    try {
        $db = \Database\Database::getInstance();
        $db->query("INSERT INTO bot_logs (level, message, context) VALUES (?, ?, ?)",
            array((string)$level, (string)$message, json_encode($context, JSON_UNESCAPED_UNICODE)));
    } catch (\Throwable $e) {
        @file_put_contents(__DIR__ . '/debug.txt', date('[Y-m-d H:i:s]') . " BOT_LOG_FAILED: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// 7. Parse JSON
$rawInputDecoded = @json_decode($rawInput, true);
if (!is_array($rawInputDecoded)) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " INVALID_JSON: " . mb_substr($rawInput, 0, 500) . "\n", FILE_APPEND);
    exit;
}

@file_put_contents($logFile, date('[Y-m-d H:i:s]') . " JSON_OK: update_id=" . ($rawInputDecoded['update_id'] ?? 'none') . " text=" . ($rawInputDecoded['message']['text'] ?? 'none') . "\n", FILE_APPEND);

// 8. Create Update
try {
    $update = UpdateFactory::create($rawInputDecoded);
} catch (\Throwable $e) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " CREATE_UPDATE_FAILED: " . $e->getMessage() . "\n", FILE_APPEND);
    exit;
}

@file_put_contents($logFile, date('[Y-m-d H:i:s]') . " UPDATE_CREATED: chatId=" . $update->getChatId() . " userId=" . $update->getUserId() . " text=" . $update->getText() . "\n", FILE_APPEND);

$isPaymentQuery = $update->isPreCheckoutQuery();
$isPaymentSuccess = $update->isSuccessfulPayment();

if (!$update || (!$update->getChatId() && !$isPaymentQuery && !$isPaymentSuccess)) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " NO_VALID_UPDATE\n", FILE_APPEND);
    exit;
}

// 9. Ignore channel messages
$chatType = $rawInputDecoded['message']['chat']['type'] ?? $rawInputDecoded['callback_query']['message']['chat']['type'] ?? null;
if ($chatType === 'channel') { exit; }
if ($chatType === 'supergroup' && $update->isMessage()) {
    $text = $update->getText() ?? '';
    $isMentioned = str_contains($text, '@' . ($_ENV['BOT_USERNAME'] ?? 'bot')) || str_starts_with($text, '/') || !empty($rawInputDecoded['message']['entities']);
    if (!$isMentioned) { exit; }
}

// 10. Update last_active_at
$baleUserId = $update->getUserId();
if ($baleUserId) {
    try {
        $db = \Database\Database::getInstance();
        $db->query("UPDATE users SET last_active_at = CURRENT_TIMESTAMP WHERE bale_user_id = ?", array($baleUserId));
    } catch (\Throwable $e) {}
}

// 11. Early callback answer
if ($update->isCallback() && $update->getCallbackId()) {
    try { (new \Modules\Bot\BaleClient())->answerCallbackQuery($update->getCallbackId()); } catch (\Throwable $e) {}
}

// 12. PreCheckoutQuery
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
    } catch (\Throwable $e) {
        @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " PRECHECKOUT_ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    }
    exit;
}

// 13. SuccessfulPayment
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
    } catch (\Throwable $e) {
        @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " PAYMENT_ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    }
    exit;
}

// 14. Duplicate check
if ($update->isDuplicate()) { exit; }

// 15. Route & Dispatch
try {
    $dispatcher = new Dispatcher($update);
    $dispatcher->dispatch($update);
} catch (\Throwable $e) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " DISPATCH_ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
}