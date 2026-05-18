<?php

// 1. Load error handler FIRST — before anything that could throw
require_once __DIR__ . '/error_handler.php';

// 2. Force reload all cached files by touching Router.php
$routerFile = __DIR__ . '/../src/Modules/Bot/Router.php';
if (file_exists($routerFile)) {
    @touch($routerFile);
}
if (function_exists('opcache_reset')) {
    @opcache_reset();
}

// 3. Load the rest of the app (autoloader, config, database)
require_once __DIR__ . '/../init.php';

use Modules\Bot\UpdateFactory;
use Modules\Bot\Router;
use Modules\Bot\Dispatcher;
use Core\Config;

// 4. Send HTTP 200 immediately so Bale API stops waiting
if (!headers_sent()) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(200);
    header('Connection: close');
    header('Content-Length: 2');
    echo "OK";
    flush();
    
    // PHP-FPM specific: close connection early
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

// Production Hardening: Logging function — NEVER throw, NEVER crash the bot
function bot_log($level, $message, $context = []) {
    try {
        $db = \Database\Database::getInstance();
        $stmt = $db->prepare("INSERT INTO bot_logs (level, message, context) VALUES (?, ?, ?)");
        $stmt->execute([$level, $message, json_encode($context, JSON_UNESCAPED_UNICODE)]);
    } catch (\Throwable $e) {
        // Silent: never let logging crash the bot
    }
}

// 5. Receive raw POST data
$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    exit;
}

@bot_log('INFO', 'Webhook update received');

// 6. Parse JSON
$updateData = @json_decode($rawInput, true);
if (!is_array($updateData)) {
    exit;
}

// 7. Create Update object
$update = UpdateFactory::create($updateData);

$isPaymentQuery = $update->isPreCheckoutQuery();
$isPaymentSuccess = $update->isSuccessfulPayment();

if (!$update || (!$update->getChatId() && !$isPaymentQuery && !$isPaymentSuccess)) {
    exit;
}

// CRITICAL: Ignore ALL channel messages
$chatType = $updateData['message']['chat']['type'] ?? $updateData['callback_query']['message']['chat']['type'] ?? null;
if ($chatType === 'channel' || $chatType === 'supergroup') {
    if ($chatType === 'channel') {
        exit;
    }
    if ($chatType === 'supergroup' && $update->isMessage()) {
        $text = $update->getText() ?? '';
        $isMentioned = str_contains($text, '@' . ($_ENV['BOT_USERNAME'] ?? 'bot'))
            || str_starts_with($text, '/')
            || ($updateData['message']['entities'] ?? []);
        if (!$isMentioned) {
            exit;
        }
    }
}

// 8. Update last_active_at for any good user action
$baleUserId = $update->getUserId();
if ($baleUserId) {
    try {
        $db = \Database\Database::getInstance();
        $db->query("UPDATE users SET last_active_at = CURRENT_TIMESTAMP WHERE bale_user_id = ?", [$baleUserId]);
    } catch (\Throwable $e) {
        // Silent
    }
}

// 9. Early Callback Answer — prevents timeout spinner
if ($update->isCallback() && $callbackId = $update->getCallbackId()) {
    try {
        $client = new \Modules\Bot\BaleClient();
        $client->answerCallbackQuery($callbackId);
    } catch (\Throwable $e) {
        // Silent
    }
}

// 10. Handle pre_checkout_query (Bale wallet payment confirmation)
if ($update->isPreCheckoutQuery()) {
    try {
        $preCheckout = $update->getPreCheckoutQuery();
        $preCheckoutId = $update->getPreCheckoutQueryId();
        $payload = $preCheckout['invoice_payload'] ?? '';
        $totalAmount = $preCheckout['total_amount'] ?? 0;
        
        $client = new \Modules\Bot\BaleClient();
        
        if (preg_match('/^plan_(\d+)_user_(\d+)$/', $payload, $matches)) {
            $planId = (int)$matches[1];
            $baleUserId = (int)$matches[2];
            $db = \Database\Database::getInstance();
            $plan = $db->query("SELECT * FROM payment_plans WHERE id = ? AND is_active = 1", [$planId])->fetch();
            if ($plan && (int)$plan['price_rial'] === $totalAmount) {
                $client->answerPreCheckoutQuery($preCheckoutId, true);
                exit;
            }
        }
        
        $client->answerPreCheckoutQuery($preCheckoutId, false, 'خطا');
    } catch (\Throwable $e) {
        @bot_log('ERROR', 'PreCheckoutQuery error', ['error' => $e->getMessage()]);
    }
    exit;
}

// 11. Handle successful_payment (Bale wallet)
if ($update->isSuccessfulPayment()) {
    try {
        $payment = $update->getSuccessfulPayment();
        $payload = $payment['invoice_payload'] ?? '';
        $totalAmount = $payment['total_amount'] ?? 0;
        $chargeId = $payment['telegram_payment_charge_id'] ?? '';
        $providerChargeId = $payment['provider_payment_charge_id'] ?? '';
        
        @bot_log('INFO', 'SuccessfulPayment received', [
            'payload' => $payload,
            'amount' => $totalAmount,
        ]);
        
        if (preg_match('/^plan_(\d+)_user_(\d+)$/', $payload, $matches)) {
            $planId = (int)$matches[1];
            $baleUserId = (int)$matches[2];
            
            $db = \Database\Database::getInstance();
            $plan = $db->query("SELECT * FROM payment_plans WHERE id = ? AND is_active = 1", [$planId])->fetch();
            $user = $db->query("SELECT id FROM users WHERE bale_user_id = ?", [$baleUserId])->fetch();
            
            if ($plan && $user) {
                $internalId = (int)$user['id'];
                $credits = (int)$plan['credits'];
                $amountRial = (int)$plan['price_rial'];
                $trackId = 'bale_' . $chargeId . '_' . time();
                
                $existing = $db->query("SELECT id FROM payments WHERE track_id = ?", [$trackId])->fetch();
                if (!$existing) {
                    $db->query(
                        "INSERT INTO payments (user_id, track_id, order_id, amount_rial, credits, plan_id, status, ref_number, verified_at) VALUES (?, ?, ?, ?, ?, ?, 'verified', ?, NOW())",
                        [$internalId, $trackId, 'BALE-' . $internalId . '-' . time(), $amountRial, $credits, $planId, $providerChargeId]
                    );
                    
                    \Modules\Bot\CreditService::addCredits($internalId, $credits, 'bale_pay_' . $trackId);
                    
                    $client = new \Modules\Bot\BaleClient();
                    $client->sendMessage($baleUserId, "✅ پرداخت با موفقیت انجام شد!\n💎 {$credits} اعتبار به حساب شما اضافه شد.");
                }
            }
        }
        
        $update->markAsProcessed();
    } catch (\Throwable $e) {
        @bot_log('ERROR', 'SuccessfulPayment error', ['error' => $e->getMessage()]);
    }
    exit;
}

// 12. Check for duplicate updates
if ($update->isDuplicate()) {
    exit;
}

// 13. Route & Dispatch
try {
    $dispatcher = new Dispatcher($update);
    $dispatcher->dispatch($update);
    $update->markAsProcessed();
} catch (\Throwable $e) {
    @bot_log('ERROR', 'Dispatch error', [
        'update_id' => $update->getId(),
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}