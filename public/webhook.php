<?php

require_once __DIR__ . '/error_handler.php';

// Force reload all cached files by touching Router.php
$routerFile = __DIR__ . '/../src/Modules/Bot/Router.php';
if (file_exists($routerFile)) {
    touch($routerFile);
}
if (function_exists('opcache_reset')) {
    opcache_reset();
}

require_once __DIR__ . '/../init.php';

use Modules\Bot\UpdateFactory;
use Modules\Bot\Router;
use Modules\Bot\Dispatcher;
use Core\Config;

// Production Hardening: Logging function
function bot_log($level, $message, $context = []) {
    try {
        $db = \Database\Database::getInstance();
        $stmt = $db->prepare("INSERT INTO bot_logs (level, message, context) VALUES (?, ?, ?)");
        $stmt->execute([$level, $message, json_encode($context)]);
    } catch (\Exception $e) {
        error_log("Bot Log Failed: " . $e->getMessage());
    }
}

// 1. Receive raw POST data
$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    bot_log('WARNING', 'Empty webhook request received');
    exit;
}

bot_log('INFO', 'Webhook update received', ['raw' => $rawInput]);

// 2. Parse JSON
$updateData = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    bot_log('ERROR', 'Invalid JSON received', ['error' => json_last_error_msg()]);
    exit;
}

// 3. Create Update object
$update = UpdateFactory::create($updateData);

// NB: PreCheckoutQuery and SuccessfulPayment don't have a chat_id, so skip chat_id check
$isPaymentQuery = $update->isPreCheckoutQuery();
$isPaymentSuccess = $update->isSuccessfulPayment();

if (!$update || (!$update->getChatId() && !$isPaymentQuery && !$isPaymentSuccess)) {
    bot_log('ERROR', 'Invalid Update or Missing Chat ID', ['data' => $updateData]);
    exit('Parse error');
}

// 4. Update last_active_at for any user action
$baleUserId = $update->getUserId();
if ($baleUserId) {
    try {
        $db = \Database\Database::getInstance();
        $db->query("UPDATE users SET last_active_at = CURRENT_TIMESTAMP WHERE bale_user_id = ?", [$baleUserId]);
    } catch (\Throwable $e) {
        // Silent — non-critical
    }
}

// 5. Early Callback Answer
if ($update->isCallback() && $callbackId = $update->getCallbackId()) {
    $client = new \Modules\Bot\BaleClient();
    $client->answerCallbackQuery($callbackId);
}

// 5. Handle pre_checkout_query (Bale wallet payment confirmation)
if ($update->isPreCheckoutQuery()) {
    $preCheckout = $update->getPreCheckoutQuery();
    $preCheckoutId = $update->getPreCheckoutQueryId();
    $payload = $preCheckout['invoice_payload'] ?? '';
    $totalAmount = $preCheckout['total_amount'] ?? 0;
    $fromUser = $preCheckout['from']['id'] ?? 0;

    bot_log('INFO', 'PreCheckoutQuery received', [
        'pre_checkout_query_id' => $preCheckoutId,
        'payload' => $payload,
        'amount' => $totalAmount,
        'user_id' => $fromUser,
    ]);

    $client = new \Modules\Bot\BaleClient();

    // Parse payload: plan_{plan_id}_user_{bale_user_id}
    if (preg_match('/^plan_(\d+)_user_(\d+)$/', $payload, $matches)) {
        $planId = (int)$matches[1];
        $baleUserId = (int)$matches[2];

        // Verify plan exists and is active
        try {
            $db = \Database\Database::getInstance();
            $plan = $db->query("SELECT * FROM payment_plans WHERE id = ? AND is_active = 1", [$planId])->fetch();
            if ($plan && (int)$plan['price_rial'] === $totalAmount) {
                // Confirm payment
                $client->answerPreCheckoutQuery($preCheckoutId, true);
                bot_log('INFO', 'PreCheckoutQuery confirmed', ['payload' => $payload]);
                exit('PreCheckoutQuery confirmed');
            }
        } catch (\Throwable $e) {
            bot_log('ERROR', 'PreCheckoutQuery verification error', ['error' => $e->getMessage()]);
        }
    }

    // Reject if anything is wrong
    $client->answerPreCheckoutQuery($preCheckoutId, false, 'خطا در تأیید سفارش. لطفاً دوباره تلاش کنید.');
    exit('PreCheckoutQuery rejected');
}

// 6. Handle successful_payment (Bale wallet payment completed)
if ($update->isSuccessfulPayment()) {
    $payment = $update->getSuccessfulPayment();
    $payload = $payment['invoice_payload'] ?? '';
    $totalAmount = $payment['total_amount'] ?? 0;
    $chargeId = $payment['telegram_payment_charge_id'] ?? '';
    $providerChargeId = $payment['provider_payment_charge_id'] ?? '';

    bot_log('INFO', 'SuccessfulPayment received', [
        'payload' => $payload,
        'amount' => $totalAmount,
        'charge_id' => $chargeId,
        'provider_charge_id' => $providerChargeId,
    ]);

    // Parse payload: plan_{plan_id}_user_{bale_user_id}
    if (preg_match('/^plan_(\d+)_user_(\d+)$/', $payload, $matches)) {
        $planId = (int)$matches[1];
        $baleUserId = (int)$matches[2];

        try {
            $db = \Database\Database::getInstance();
            $plan = $db->query("SELECT * FROM payment_plans WHERE id = ? AND is_active = 1", [$planId])->fetch();
            $user = $db->query("SELECT id FROM users WHERE bale_user_id = ?", [$baleUserId])->fetch();

            if ($plan && $user) {
                $internalId = (int)$user['id'];
                $credits = (int)$plan['credits'];
                $amountRial = (int)$plan['price_rial'];
                $trackId = 'bale_' . $chargeId . '_' . time();

                // Check for duplicate (idempotency)
                $existing = $db->query("SELECT id FROM payments WHERE track_id = ?", [$trackId])->fetch();
                if (!$existing) {
                    // Insert payment record
                    $db->query(
                        "INSERT INTO payments (user_id, track_id, order_id, amount_rial, credits, plan_id, status, ref_number, verified_at) VALUES (?, ?, ?, ?, ?, ?, 'verified', ?, NOW())",
                        [$internalId, $trackId, 'BALE-' . $internalId . '-' . time(), $amountRial, $credits, $planId, $providerChargeId]
                    );

                    // Add credits
                    $refId = 'bale_pay_' . $trackId;
                    \Modules\Bot\CreditService::addCredits($internalId, $credits, $refId);

                    // Notify user
                    $chatId = $baleUserId;
                    $client = new \Modules\Bot\BaleClient();
                    $client->sendMessage($chatId, "✅ پرداخت با موفقیت انجام شد!\n💎 {$credits} اعتبار به حساب شما اضافه شد.");

                    bot_log('INFO', 'Bale payment processed', [
                        'user_id' => $internalId,
                        'credits' => $credits,
                        'track_id' => $trackId,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            bot_log('ERROR', 'SuccessfulPayment processing error', ['error' => $e->getMessage()]);
        }
    }

    $update->markAsProcessed();
    exit('SuccessfulPayment processed');
}

// 7. Check for duplicate updates
if ($update->isDuplicate()) {
    bot_log('INFO', 'Duplicate update ignored', ['update_id' => $update->getId()]);
    exit('Duplicate update');
}

// 8. Route & Dispatch
try {
    $dispatcher = new Dispatcher($update);
    $dispatcher->dispatch($update);
    
    $update->markAsProcessed();

} catch (\Throwable $e) {
    bot_log('ERROR', 'Dispatch error', [
        'update_id' => $update->getId(),
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(200); // Always return 200 to Bale to avoid retries on logic errors
}
