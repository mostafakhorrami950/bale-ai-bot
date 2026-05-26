<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\CreditService;
use Modules\Bot\Models\User;
use Database\Database;
use Database\Logger;
use Core\Config;
use Core\BotTextService;

class BuyCreditHandler extends BaseHandler
{
    public function handle($update): void
    {
        try {
            $chatId = $update->getChatId();
            $userId = $update->getUserId();
            $text = $update->getText();
            $callbackData = $update->getCallbackData();

            // PRIORITY: PreCheckoutQuery — must respond within 10 seconds
            if ($update->isPreCheckoutQuery()) {
                $this->handlePreCheckoutQuery($update);
                return;
            }

            // PRIORITY: SuccessfulPayment — credit the user
            if ($update->isSuccessfulPayment()) {
                $this->handleSuccessfulPayment($update);
                return;
            }

            // Membership check
            if (!$this->checkMembership($userId, $chatId)) return;

            if ($callbackData && str_starts_with($callbackData, 'plan_')) {
                $callbackId = $update->getCallbackId();
                $this->processPlan($chatId, $userId, $callbackId, $callbackData);
                return;
            }

            if ($callbackData && str_starts_with($callbackData, 'pay_zibal_')) {
                $planId = (int) str_replace('pay_zibal_', '', $callbackData);
                $plan = Database::getInstance()->query("SELECT * FROM payment_plans WHERE id = ? AND is_active=1", [$planId])->fetch();
                $user = User::findByBaleId($userId);
                if ($plan && $user) {
                    $this->processZibalPayment($chatId, $userId, $plan, $user);
                }
                return;
            }

            if ($callbackData && str_starts_with($callbackData, 'pay_bale_')) {
                $planId = (int) str_replace('pay_bale_', '', $callbackData);
                $plan = Database::getInstance()->query("SELECT * FROM payment_plans WHERE id = ? AND is_active=1", [$planId])->fetch();
                $user = User::findByBaleId($userId);
                $token = Database::getInstance()->query("SELECT value FROM settings WHERE key_name = 'bale_provider_token'")->fetch()['value'] ?? '';
                if ($plan && $user && $token) {
                    $this->processBalePayment($chatId, $userId, $plan, $user, $token);
                }
                return;
            }

            $this->showPlans($chatId, $userId);
        } catch (\Throwable $e) {
            Logger::error('BuyCreditHandler exception', [
                'user_id' => $update->getUserId(),
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            error_log("BuyCreditHandler FATAL ERROR: " . $e->getMessage());
            // Only send message to user if we have a valid chat_id
            $catchChatId = $update->getChatId();
            if ($catchChatId) {
                $this->baleClient->sendMessage($catchChatId, "⚠️ متأسفانه مشکلی در بارگزاری پلن‌ها پیش آمد.\nعلت: " . $e->getMessage());
            }
        }
    }

    /**
     * Handle PreCheckoutQuery — Bale wallet asks us to confirm/reject payment.
     * Must respond within 10 seconds or payment is cancelled.
     * 
     * CRITICAL: This method MUST always call answerPreCheckoutQuery() even on error,
     * otherwise Bale will cancel the payment.
     */
    private function handlePreCheckoutQuery($update): void
    {
        $pq = $update->getPreCheckoutQuery();
        if (!$pq) return;

        $preCheckoutQueryId = $pq['id'] ?? '';
        if (empty($preCheckoutQueryId)) return;

        $payload = $pq['invoice_payload'] ?? '';
        $totalAmount = $pq['total_amount'] ?? 0;
        $fromUserId = $pq['from']['id'] ?? null;

        Logger::info('BuyCreditHandler: PreCheckoutQuery received', [
            'id' => $preCheckoutQueryId,
            'payload' => $payload,
            'amount' => $totalAmount,
        ]);

        try {
            // Parse payload: plan_X_user_Y
            $ok = false;
            $errorMsg = '';
            $planId = 0;

            if (preg_match('/^plan_(\d+)_user_(\d+)$/', $payload, $m)) {
                $planId = (int)$m[1];
                $baleUserId = (int)$m[2];

                // Verify the plan exists and is active
                $plan = Database::getInstance()->query("SELECT * FROM payment_plans WHERE id = ? AND is_active=1", [$planId])->fetch();
                if ($plan && $baleUserId > 0) {
                    // Verify amount matches
                    $expectedAmount = (int)$plan['price_rial'];
                    if ($totalAmount === $expectedAmount) {
                        // Verify the user exists
                        $user = User::findByBaleId($baleUserId);
                        if ($user) {
                            $ok = true;
                        } else {
                            $errorMsg = 'کاربر یافت نشد.';
                        }
                    } else {
                        $errorMsg = 'مبلغ صورتحساب نامعتبر است.';
                    }
                } else {
                    $errorMsg = 'پلن یافت نشد یا غیرفعال است.';
                }
            } else {
                $errorMsg = 'payload نامعتبر است.';
            }

            // Log the pre_checkout_query to payments table for tracking
            if ($ok && $planId > 0) {
                try {
                    Database::getInstance()->query(
                        "INSERT INTO payments (user_id, track_id, order_id, amount_rial, status, plan_id) VALUES (?, ?, ?, ?, 'pending', ?)",
                        [$fromUserId ?? 0, $preCheckoutQueryId, 'pq_' . $preCheckoutQueryId, $totalAmount, $planId]
                    );
                } catch (\Throwable $e) {
                    Logger::error('BuyCreditHandler: Failed to log pre_checkout to payments', [
                        'error' => $e->getMessage(),
                    ]);
                    // Continue — still answer the query
                }
            }
        } catch (\Throwable $e) {
            Logger::error('BuyCreditHandler: PreCheckoutQuery validation error', [
                'error' => $e->getMessage(),
            ]);
            $ok = false;
            $errorMsg = 'خطای داخلی سامانه';
        }

        // Answer the pre-checkout query (MUST be within 10 seconds ALWAYS)
        // This is CRITICAL — Bale cancels payment if we don't respond in time
        try {
            $this->baleClient->answerPreCheckoutQuery($preCheckoutQueryId, $ok, $ok ? null : ($errorMsg ?: 'خطای ناشناخته'));
        } catch (\Throwable $e) {
            Logger::error('BuyCreditHandler: answerPreCheckoutQuery failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle SuccessfulPayment — credit the user after successful Bale wallet payment.
     */
    private function handleSuccessfulPayment($update): void
    {
        $sp = $update->getSuccessfulPayment();
        if (!$sp) return;

        $payload = $sp['invoice_payload'] ?? '';
        $totalAmount = (int)($sp['total_amount'] ?? 0);
        $telegramPaymentChargeId = $sp['telegram_payment_charge_id'] ?? '';
        $providerPaymentChargeId = $sp['provider_payment_charge_id'] ?? '';

        Logger::info('BuyCreditHandler: SuccessfulPayment received', [
            'payload' => $payload,
            'amount' => $totalAmount,
            'telegram_charge_id' => $telegramPaymentChargeId,
            'provider_charge_id' => $providerPaymentChargeId,
        ]);

        // Parse payload: plan_X_user_Y
        if (!preg_match('/^plan_(\d+)_user_(\d+)$/', $payload, $m)) {
            Logger::error('BuyCreditHandler: invalid payload in SuccessfulPayment', ['payload' => $payload]);
            return;
        }

        $planId = (int)$m[1];
        $baleUserId = (int)$m[2];

        $plan = Database::getInstance()->query("SELECT * FROM payment_plans WHERE id = ? AND is_active=1", [$planId])->fetch();
        if (!$plan) {
            Logger::error('BuyCreditHandler: plan not found in SuccessfulPayment', ['plan_id' => $planId]);
            return;
        }

        $user = User::findByBaleId($baleUserId);
        if (!$user) {
            Logger::error('BuyCreditHandler: user not found in SuccessfulPayment', ['bale_user_id' => $baleUserId]);
            return;
        }

        $internalId = (int)$user['id'];
        $creditsToAdd = (int)$plan['credits'];

        // Idempotency: check if this charge_id was already credited
        $existingCredit = Database::getInstance()->query(
            "SELECT id FROM payments WHERE track_id = ? AND status = 'verified'",
            [$telegramPaymentChargeId]
        )->fetch();
        if ($existingCredit) {
            Logger::info('BuyCreditHandler: duplicate successful payment ignored', ['charge_id' => $telegramPaymentChargeId]);
            return;
        }

        // Add credits to user
        $referenceId = 'bale_pay_' . $telegramPaymentChargeId . '_' . time();
        $success = CreditService::addCredits($internalId, $creditsToAdd, $referenceId);

        // Update payment record
        Database::getInstance()->query(
            "UPDATE payments SET status = 'verified', track_id = CONCAT(track_id, ',', ?) WHERE user_id = ? AND plan_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
            [$telegramPaymentChargeId, $internalId, $planId]
        );

        if ($success) {
            // Send confirmation to user
            $chatId = $update->getChatId();
            $message = "✅ پرداخت شما با موفقیت انجام شد!\n\n"
                . "📦 پلن: {$plan['name']}\n"
                . "💰 مبلغ: " . number_format($totalAmount / 10) . " تومان\n"
                . "💎 اعتبار افزوده شده: " . number_format($creditsToAdd) . " کردیت\n"
                . "📄 کد پیگیری: {$providerPaymentChargeId}";
            if ($chatId) {
                $this->baleClient->sendMessage($chatId, $message);
            }

            Logger::info('BuyCreditHandler: credits added successfully', [
                'user_id' => $internalId,
                'credits' => $creditsToAdd,
                'reference' => $referenceId,
            ]);
        } else {
            Logger::error('BuyCreditHandler: failed to add credits', [
                'user_id' => $internalId,
                'credits' => $creditsToAdd,
            ]);
        }
    }

    private function showPlans(int $chatId, int $userId): void
    {
        error_log("BuyCreditHandler::showPlans CALLED for user $userId");
        // Check if payment_plans table exists
        try {
            $db = Database::getInstance();
            $plans = $db->query("SELECT * FROM payment_plans WHERE is_active=1")->fetchAll();
            error_log("BuyCreditHandler::showPlans FOUND " . count($plans) . " plans");
        } catch (\PDOException $e) {
            error_log("BuyCreditHandler SQL ERROR: " . $e->getMessage());
            Logger::error('BuyCreditHandler: payment_plans table error', ['error' => $e->getMessage()]);
            $this->baleClient->sendMessage($chatId, BotTextService::get('plans_load_error'));
            return;
        }

        if (empty($plans)) {
            error_log("BuyCreditHandler: NO ACTIVE PLANS IN DB");
            $this->baleClient->sendMessage($chatId, BotTextService::get('no_active_plans'));
            return;
        }

        $keyboard = ['inline_keyboard' => []];
        foreach ($plans as $plan) {
            $label = "{$plan['name']} - " . number_format($plan['credits']) . " اعتبار - " . number_format($plan['price_rial'] / 10) . " تومان";
            $keyboard['inline_keyboard'][] = [
                ['text' => $label, 'callback_data' => 'plan_' . $plan['id']]
            ];
        }

        $this->baleClient->sendMessage($chatId, BotTextService::get('plans_title'), $keyboard);
    }

    private function processPlan(int $chatId, int $userId, ?string $callbackId, string $callbackData): void
    {
        $planId = (int) str_replace('plan_', '', $callbackData);
        if ($planId <= 0) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('invalid_plan'));
            return;
        }

        $plans = Database::getInstance()->query("SELECT * FROM payment_plans WHERE id = ? AND is_active=1", [$planId])->fetchAll();
        if (empty($plans)) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('plan_not_found'));
            return;
        }

        $plan = $plans[0];
        $user = User::findByBaleId($userId);
        if (!$user) {
            $this->baleClient->sendMessage($chatId, BotTextService::get('user_not_found'));
            return;
        }

        if ($callbackId) {
            $this->baleClient->answerCallbackQuery($callbackId);
        }

        // Get active payment methods from settings
        $db = Database::getInstance();
        $settings = $db->query("SELECT key_name, value FROM settings WHERE key_name IN ('payment_method_zibal', 'payment_method_bale', 'bale_provider_token')")->fetchAll();
        $config = [];
        foreach ($settings as $s) {
            $config[$s['key_name']] = $s['value'];
        }

        $zibalActive = ($config['payment_method_zibal'] ?? 'on') === 'on';
        $baleActive = ($config['payment_method_bale'] ?? 'off') === 'on';
        $baleProviderToken = $config['bale_provider_token'] ?? '';

        // If both are active, show payment method selection
        if ($zibalActive && $baleActive && !empty($baleProviderToken)) {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '💳 پرداخت با زیبال', 'callback_data' => 'pay_zibal_' . $planId]],
                    [['text' => '💰 پرداخت با کیف پول بله', 'callback_data' => 'pay_bale_' . $planId]],
                ]
            ];
            $this->baleClient->sendMessage($chatId, BotTextService::get('payment_method_selection'), $keyboard);
            return;
        }

        // Only one method active — proceed directly
        if ($zibalActive) {
            $this->processZibalPayment($chatId, $userId, $plan, $user);
        } elseif ($baleActive && !empty($baleProviderToken)) {
            $this->processBalePayment($chatId, $userId, $plan, $user, $baleProviderToken);
        } else {
            $this->baleClient->sendMessage($chatId, BotTextService::get('no_payment_method'));
        }
    }

    /**
     * Process payment via Zibal gateway.
     */
    private function processZibalPayment(int $chatId, int $userId, array $plan, array $user): void
    {
        $this->baleClient->sendMessage($chatId, BotTextService::get('zibal_connecting'));

        try {
            $paymentService = new \Modules\Payment\ZibalService();
            $internalId = (int) $user['id'];
            $amountRial = (int) $plan['price_rial'];
            $orderId = 'ORD-' . $internalId . '-' . time();
            $description = "خرید پلن {$plan['name']} - کاربر {$user['id']}";

            $result = $paymentService->requestPayment($amountRial, $orderId, $description);

            if (isset($result['error'])) {
                Logger::error('BuyCreditHandler: Zibal payment request failed', [
                    'user_id' => $internalId,
                    'plan'    => $plan['name'],
                    'error'   => $result['error'],
                ]);
                $this->baleClient->sendMessage($chatId, BotTextService::get('zibal_connection_error'));
                return;
            }

            if (isset($result['trackId'])) {
                $trackId = $result['trackId'];

                Database::getInstance()->query(
                    "INSERT INTO payments (user_id, track_id, order_id, amount_rial, credits, plan_id, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')",
                    [$internalId, $trackId, $orderId, $amountRial, $plan['credits'], $plan['id']]
                );

                $paymentUrl = "https://gateway.zibal.ir/start/{$trackId}";
                $message = BotTextService::get('zibal_payment_message', [
                    'plan_name' => $plan['name'],
                    'amount' => number_format($amountRial / 10),
                    'credits' => $plan['credits'],
                    'payment_url' => $paymentUrl,
                ]);

                $this->baleClient->sendMessage($chatId, $message);
            }
        } catch (\Throwable $e) {
            Logger::error('BuyCreditHandler: processZibalPayment error', [
                'user_id' => $userId,
                'plan_id' => $plan['id'],
                'error'   => $e->getMessage(),
            ]);
            $this->baleClient->sendMessage($chatId, BotTextService::get('zibal_general_error'));
        }
    }

    /**
     * Process payment via Bale wallet (sendInvoice).
     */
    private function processBalePayment(int $chatId, int $userId, array $plan, array $user, string $providerToken): void
    {
        $internalId = (int) $user['id'];
        $amountRial = (int) $plan['price_rial'];
        $payload = 'plan_' . $plan['id'] . '_user_' . $userId;

        // Prices array: label + amount in Rial
        $prices = [
            ['label' => $plan['name'], 'amount' => $amountRial]
        ];

        $result = $this->baleClient->sendInvoice(
            $chatId,
            $plan['name'],
            "خرید {$plan['credits']} اعتبار - پلن {$plan['name']}",
            $payload,
            $providerToken,
            $prices
        );

        if (!isset($result['ok']) || $result['ok'] !== true) {
            $errMsg = $result['description'] ?? 'خطا در ارسال صورتحساب';
            Logger::error('BuyCreditHandler: Bale sendInvoice failed', [
                'user_id' => $internalId,
                'plan'    => $plan['name'],
                'error'   => $errMsg,
            ]);
            $this->baleClient->sendMessage($chatId, BotTextService::get('bale_invoice_error', ['error' => $errMsg]));
            return;
        }

        Logger::info('BuyCreditHandler: Bale invoice sent', [
            'user_id' => $internalId,
            'plan'    => $plan['name'],
            'payload' => $payload,
        ]);
    }
}