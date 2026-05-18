<?php

/**
 * Bale AI Bot — Webhook Entry Point
 * 
 * CRITICAL: php://input MUST be read BEFORE any other code runs.
 * Some hosting environments close php://input after ini_set or session_start.
 */

// 1. Read raw input IMMEDIATELY — before anything else
$rawInput = @file_get_contents('php://input');
$inputLen = strlen($rawInput ?? '');

// 2. Log to debug.txt right away
$logFile = __DIR__ . '/debug.txt';
@file_put_contents($logFile, date('[Y-m-d H:i:s]') . " WEBHOOK_HIT: {$inputLen} bytes | METHOD: {$_SERVER['REQUEST_METHOD']} | CT: {$_SERVER['CONTENT_TYPE']}\n", FILE_APPEND);

// 3. Load app — BEFORE any output, so session_start() can work
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/../init.php';

// 4. Close session immediately — webhook doesn't need write access
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

use Modules\Bot\UpdateFactory;
use Modules\Bot\Dispatcher;

// 5. HTTP 200 ALWAYS — Bale needs this to stop retrying
if (!headers_sent()) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
}
echo "OK";
flush();
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

// 6. If empty input — Bale health ping, just exit
if (empty($rawInput)) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " EMPTY_INPUT — health ping\n", FILE_APPEND);
    exit;
}

// 7. Silent logger
function bot_log($level, $message, $context = array()) {
    try {
        $db = \Database\Database::getInstance();
        $db->query("INSERT INTO bot_logs (level, message, context) VALUES (?, ?, ?)",
            array((string)$level, (string)$message, json_encode($context, JSON_UNESCAPED_UNICODE)));
    } catch (\Throwable $e) {
        @file_put_contents(__DIR__ . '/debug.txt', date('[Y-m-d H:i:s]') . " BOT_LOG_FAILED: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// 8. Parse JSON
$updateData = @json_decode($rawInput, true);
if (!is_array($updateData)) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " INVALID_JSON: " . mb_substr($rawInput, 0, 500) . "\n", FILE_APPEND);
    exit;
}

@file_put_contents($logFile, date('[Y-m-d H:i:s]') . " JSON_OK: update_id=" . ($updateData['update_id'] ?? 'none') . " text=" . ($updateData['message']['text'] ?? 'none') . "\n", FILE_APPEND);

// 9. Create Update
try {
    $update = UpdateFactory::create($updateData);
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

// 10. Ignore channel messages
$chatType = $updateData['message']['chat']['type'] ?? $updateData['callback_query']['message']['chat']['type'] ?? null;
if ($chatType === 'channel') { exit; }
if ($chatType === 'supergroup' && $update->isMessage()) {
    $text = $update->getText() ?? '';
    $isMentioned = str_contains($text, '@' . ($_ENV['BOT_USERNAME'] ?? 'bot')) || str_starts_with($text, '/') || !empty($updateData['message']['entities']);
    if (!$isMentioned) { exit; }
}

// 11. Update last_active_at
$baleUserId = $update->getUserId();
if ($baleUserId) {
    try {
        $db = \Database\Database::getInstance();
        $db->query("UPDATE users SET last_active_at = CURRENT_TIMESTAMP WHERE bale_user_id = ?", array($baleUserId));
    } catch (\Throwable $e) {}
}

// 12. Early callback answer
if ($update->isCallback() && $update->getCallbackId()) {
    try { (new \Modules\Bot\BaleClient())->answerCallbackQuery($update->getCallbackId()); } catch (\Throwable $e) {}
}

// 13. PreCheckoutQuery
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

// 14. SuccessfulPayment
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

// 15. Duplicate check
if ($update->isDuplicate()) { exit; }

// 16. Route & Dispatch
try {
    $dispatcher = new Dispatcher($update);
    $dispatcher->dispatch($update);
} catch (\Throwable $e) {
    @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " DISPATCH_ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
}