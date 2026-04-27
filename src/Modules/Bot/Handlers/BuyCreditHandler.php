<?php

namespace Modules\Bot\Handlers;

use Modules\Bot\CreditService;
use Modules\Bot\Models\User;
use Database\Database;
use Database\Logger;
use Core\Config;

class BuyCreditHandler extends BaseHandler
{
    public function handle($update): void
    {
        try {
            $chatId = $update->getChatId();
            $userId = $update->getUserId();
            $text = $update->getText();
            $callbackData = $update->getCallbackData();

            if ($text === '💳 شارژ اعتبار') {
                $this->showPlans($chatId, $userId);
                return;
            }

            if ($callbackData && str_starts_with($callbackData, 'plan_')) {
                $this->processPlan($chatId, $userId, $callbackData);
                return;
            }

            $this->showPlans($chatId, $userId);
        } catch (\Throwable $e) {
            Logger::error('BuyCreditHandler exception', [
                'user_id' => $update->getUserId(),
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            $this->baleClient->sendMessage($update->getChatId(), "⚠️ متأسفانه مشکلی پیش آمد. لطفاً دوباره تلاش کنید.");
        }
    }

    private function showPlans(int $chatId, int $userId): void
    {
        // Check if payment_plans table exists
        try {
            $plans = Database::getInstance()->query("SELECT * FROM payment_plans WHERE is_active=1")->fetchAll();
        } catch (\PDOException $e) {
            Logger::error('BuyCreditHandler: payment_plans table missing', ['error' => $e->getMessage()]);
            $this->baleClient->sendMessage($chatId, "⚠️ در حال حاضر پلن‌های شارژ در دسترس نیست. لطفاً بعداً تلاش کنید.");
            return;
        }

        if (empty($plans)) {
            $this->baleClient->sendMessage($chatId, "⚠️ در حال حاضر پلن‌های شارژ در دسترس نیست. لطفاً بعداً تلاش کنید.");
            return;
        }

        $keyboard = ['inline_keyboard' => []];
        foreach ($plans as $plan) {
            $label = "{$plan['title']} - " . number_format($plan['credits']) . " اعتبار - " . number_format($plan['price_rial']) . " تومان";
            $keyboard['inline_keyboard'][] = [
                ['text' => $label, 'callback_data' => 'plan_' . $plan['id']]
            ];
        }

        $this->baleClient->sendMessage($chatId, "💰 **لطفاً یکی از پلن‌های زیر را انتخاب کنید:**\n\n", json_encode($keyboard));
    }

    private function processPlan(int $chatId, int $userId, string $callbackData): void
    {
        $planId = (int) str_replace('plan_', '', $callbackData);
        if ($planId <= 0) {
            $this->baleClient->sendMessage($chatId, "⚠️ پلن نامعتبر است.");
            return;
        }

        $plans = Database::getInstance()->query("SELECT * FROM payment_plans WHERE id = ? AND is_active=1", [$planId])->fetchAll();
        if (empty($plans)) {
            $this->baleClient->sendMessage($chatId, "⚠️ پلن مورد نظر یافت نشد.");
            return;
        }

        $plan = $plans[0];
        $user = User::findByBaleId($userId);
        if (!$user) {
            $this->baleClient->sendMessage($chatId, "⚠️ کاربر یافت نشد.");
            return;
        }

        $this->baleClient->answerCallbackQuery($update->getCallbackId());

        $this->baleClient->sendMessage($chatId, "⏳ در حال اتصال به درگاه پرداخت... لطفاً صبر کنید.");

        try {
            $paymentService = new \Modules\Payment\ZibalService();
            $internalId = (int) $user['id'];
            $amountRial = (int) $plan['price_rial'];
            $callbackUrl = Config::get('BASE_URL', 'https://mobixai.ir') . '/payment/verify.php';

            $result = $paymentService->requestPayment($amountRial, $callbackUrl, $internalId);

            if (isset($result['error'])) {
                Logger::error('BuyCreditHandler: payment request failed', [
                    'user_id' => $internalId,
                    'plan'    => $plan['title'],
                    'error'   => $result['error'],
                ]);
                $this->baleClient->sendMessage($chatId, "⚠️ متأسفانه مشکلی در اتصال به درگاه پرداخت پیش آمد.");
                return;
            }

            if (isset($result['trackId'])) {
                $trackId = $result['trackId'];

                Database::getInstance()->query(
                    "INSERT INTO payments (user_id, track_id, amount_rial, credits, plan_id, status) VALUES (?, ?, ?, ?, ?, 'pending')",
                    [$internalId, $trackId, $amountRial, $plan['credits'], $plan['id']]
                );

                $paymentUrl = "https://gateway.zibal.ir/start/{$trackId}";
                $message = "💳 **پرداخت برای پلن: {$plan['title']}**\n\n";
                $message .= "💰 مبلغ: " . number_format($amountRial) . " ریال\n";
                $message .= "💎 اعتبار: {$plan['credits']} کردیت\n\n";
                $message .= "🔗 لینک پرداخت:\n{$paymentUrl}\n\n";
                $message .= "⏳ پس از پرداخت، به صورت خودکار اعتبار به حساب شما اضافه می‌شود.";

                $this->baleClient->sendMessage($chatId, $message);
            }
        } catch (\Throwable $e) {
            Logger::error('BuyCreditHandler: processPlan error', [
                'user_id' => $userId,
                'plan_id' => $planId,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            $this->baleClient->sendMessage($chatId, "⚠️ متأسفانه مشکلی پیش آمد. لطفاً دوباره تلاش کنید.");
        }
    }
}