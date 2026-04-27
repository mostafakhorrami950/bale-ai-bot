<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\CreditService;
use Modules\Bot\Models\User;
use Modules\Payment\ZibalService;
use Database\Database;
use Database\Logger;

class BuyCreditHandler extends BaseHandler
{
    /**
     * Default payment plans (fallback if payment_plans table is empty).
     */
    private const DEFAULT_PLANS = [
        ['plan_id' => 'basic',    'name' => 'پایه',     'credits' => 50,  'price_rial' => 49000],
        ['plan_id' => 'standard', 'name' => 'استاندارد', 'credits' => 150, 'price_rial' => 139000],
        ['plan_id' => 'premium',  'name' => 'حرفه‌ای',   'credits' => 500, 'price_rial' => 449000],
    ];

    public function handle(): void
    {
        try {
            $chatId  = $this->update->getChatId();
            $userId  = $this->update->getUserId();
            $text    = $this->update->getText();
            $isCallback = $this->update->isCallback();
            $callbackData = $this->update->getCallbackData();

            // Handle keyboard button "💳 شارژ اعتبار"
            if ($text === '💳 شارژ اعتبار') {
                $this->showPlans($chatId, $userId);
                return;
            }

            // Handle callback — could be plan selection or product list
            if ($isCallback) {
                // Plan selection from inline keyboard
                if (strpos($callbackData, 'plan_') === 0) {
                    $planId = substr($callbackData, 5); // e.g. "basic"
                    $this->handlePlanSelection($chatId, $userId, $planId);
                    return;
                }

                // "buy_credit" callback — show plans
                $this->showPlans($chatId, $userId);
                return;
            }

            // Fallback
            $this->sendMessage("🤖 لطفاً از منوی زیر گزینه‌ای را انتخاب کنید:", $this->getMainMenuKeyboard());
        } catch (\Throwable $e) {
            Logger::error('BuyCreditHandler exception', [
                'user_id' => $this->update->getUserId(),
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            $this->sendMessage("⚠️ متأسفانه مشکلی پیش آمد. لطفاً دوباره تلاش کنید.");
        }
    }

    /**
     * Show available purchase plans to the user.
     */
    private function showPlans(int $chatId, int $userId): void
    {
        // Fetch plans from DB
        $plans = $this->getPlansFromDb();

        if (empty($plans)) {
            // Fallback to default plans
            $plans = self::DEFAULT_PLANS;
        }

        $message = "💳 **شارژ اعتبار**\n\n";
        $message .= "یکی از طرح‌های زیر را انتخاب کنید:\n\n";

        $keyboard = [
            'inline_keyboard' => [],
        ];

        foreach ($plans as $plan) {
            $creditText = number_format($plan['credits']);
            // M7: Fix price unit — DB stores Rial, display as Toman
            $priceToman = number_format($plan['price_rial'] / 10);
            $message .= "🔹 {$plan['name']}\n";
            $message .= "   {$creditText} اعتبار — {$priceToman} تومان\n\n";

            $keyboard['inline_keyboard'][] = [
                [
                    'text'          => "{$plan['name']} — {$priceToman} تومان",
                    'callback_data' => "plan_{$plan['plan_id']}",
                ],
            ];
        }

        $this->bale->sendMessage($chatId, $message, $keyboard);
    }

    /**
     * Handle user selecting a payment plan.
     */
    private function handlePlanSelection(int $chatId, int $userId, string $planId): void
    {
        // Find plan details
        $plans = $this->getPlansFromDb();
        if (empty($plans)) {
            $plans = self::DEFAULT_PLANS;
        }

        $selectedPlan = null;
        foreach ($plans as $plan) {
            if ($plan['plan_id'] === $planId) {
                $selectedPlan = $plan;
                break;
            }
        }

        if (!$selectedPlan) {
            $this->sendMessage("❌ طرح انتخابی نامعتبر است. لطفاً دوباره تلاش کنید.");
            return;
        }

        // Get user internal ID
        $user = User::findByBaleId($userId);
        if (!$user) {
            $this->sendMessage("⚠️ کاربر یافت نشد. لطفاً ابتدا با /start ثبت‌نام کنید.");
            return;
        }
        $internalId = (int) $user['id'];

        // Generate unique order ID
        $orderId = 'pay_' . $internalId . '_' . time() . '_' . bin2hex(random_bytes(4));

        // Create pending payment record in DB — store Zibal's track_id AFTER success
        $paymentId = $this->createPaymentRecord($internalId, $selectedPlan);
        if (!$paymentId) {
            Logger::error('BuyCreditHandler: failed to create payment record', [
                'user_id' => $internalId,
                'plan'    => $selectedPlan,
                'orderId' => $orderId,
            ]);
            $this->sendMessage("⚠️ مشکلی در ایجاد درخواست پرداخت پیش آمد. لطفاً دوباره تلاش کنید.");
            return;
        }

        // Call Zibal API to request payment
        $zibal = new ZibalService();
        $description = "خرید {$selectedPlan['credits']} اعتبار — طرح {$selectedPlan['name']}";
        $result = $zibal->requestPayment($selectedPlan['price_rial'], $orderId, $description);

        if (!$result['success']) {
            Logger::error('BuyCreditHandler: Zibal requestPayment failed', [
                'user_id' => $internalId,
                'plan'    => $selectedPlan,
                'error'   => $result['error'],
            ]);
            $this->sendMessage("⚠️ درگاه پرداخت موقتاً در دسترس نیست. لطفاً بعداً تلاش کنید.");
            return;
        }

        $trackId = $result['trackId'];

        // Update payment record with track_id from Zibal
        $this->updatePaymentTrackId($paymentId, $trackId);

        // Generate payment URL
        $paymentUrl = $zibal->generatePaymentUrl($trackId);

        // Send payment link to user
        $message = "✅ صورتحساب پرداخت ایجاد شد.\n\n";
        $message .= "📋 طرح: {$selectedPlan['name']}\n";
        $message .= "💰 مبلغ: " . number_format($selectedPlan['price_rial']) . " تومان\n";
        $message .= "⭐ اعتبار: {$selectedPlan['credits']}\n\n";
        $message .= "🔗 برای پرداخت روی لینک زیر کلیک کنید:\n";
        $message .= $paymentUrl . "\n\n";
        $message .= "⚠️ پس از پرداخت، اعتبار شما به صورت خودکار به حساب شما اضافه خواهد شد.";

        $this->sendMessage($message);
    }

    /**
     * Fetch active payment plans from database.
     */
    private function getPlansFromDb(): array
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT plan_id, name, credits, price_rial FROM payment_plans WHERE is_active = 1 ORDER BY price_rial ASC");
            $rows = $stmt->fetchAll();
            if (!empty($rows)) {
                return $rows;
            }
        } catch (\Throwable $e) {
            Logger::error('BuyCreditHandler: getPlansFromDb failed', [
                'error' => $e->getMessage(),
            ]);
        }
        return [];
    }

    /**
     * Create a pending payment record in the payments table.
     * N4: Uses a placeholder track_id initially, then updates with Zibal's real trackId after success.
     *
     * @param int   $userId
     * @param array $plan     ['plan_id', 'name', 'credits', 'price_rial']
     *
     * @return int|null  Inserted payment ID, or null on failure
     */
    private function createPaymentRecord(int $userId, array $plan): ?int
    {
        try {
            $db = Database::getInstance();
            // Use a placeholder track_id initially — will be updated with real Zibal trackId
            $placeholderId = 'pending_' . $userId . '_' . time();
            $stmt = $db->query(
                "INSERT INTO payments (user_id, track_id, amount_rial, credits, plan_id, status) VALUES (?, ?, ?, ?, ?, 'pending')",
                [$userId, $placeholderId, $plan['price_rial'], $plan['credits'], $plan['plan_id']]
            );
            // Get the insert ID
            $conn = $db->getConnection();
            return (int) $conn->lastInsertId();
        } catch (\Throwable $e) {
            Logger::error('BuyCreditHandler: createPaymentRecord failed', [
                'user_id' => $userId,
                'plan'    => $plan,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Update payment record with track_id from Zibal.
     */
    private function updatePaymentTrackId(int $paymentId, string $trackId): void
    {
        try {
            $db = Database::getInstance();
            $db->query(
                "UPDATE payments SET track_id = ? WHERE id = ? AND status = 'pending'",
                [$trackId, $paymentId]
            );
        } catch (\Throwable $e) {
            Logger::error('BuyCreditHandler: updatePaymentTrackId failed', [
                'payment_id' => $paymentId,
                'track_id'   => $trackId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build main menu keyboard.
     */
    private function getMainMenuKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => "🎨 ساخت تصویر"], ['text' => "🖼️ ویرایش عکس"]],
                [['text' => "👤 حساب من"], ['text' => "💳 شارژ اعتبار"]],
                [['text' => "❓ راهنما"]]
            ],
            'resize_keyboard' => true
        ];
    }
}