<?php
/**
 * Zibal Payment Verification Page
 * 
 * This is the callback URL that Zibal redirects the user to after payment.
 * It does NOT initiate payments — the bot handles that via BuyCreditHandler.
 * 
 * Flow:
 * 1. Receive trackId from GET
 * 2. Call Zibal verify API (NEVER trust GET params alone)
 * 3. Update payment record and add credits to user
 * 4. Notify user on Bale via bot
 * 5. Show Persian result page
 */


try {
    require_once __DIR__ . '/../../init.php';
} catch (\Throwable $e) {
    displayErrorPage("خطا در بارگذاری سیستم", $e->getMessage());
    exit;
}

use Modules\Payment\ZibalService;
use Modules\Bot\CreditService;
use Modules\Bot\BaleClient;
use Database\Database;
use Database\Logger;

// ============================================================
// 1. Get trackId from GET parameter
// ============================================================
$trackId = $_GET['trackId'] ?? $_GET['track_id'] ?? '';


if (empty($trackId)) {
    displayResult(
        false,
        '❌ خطا',
        'شناسه تراکنش دریافت نشد.',
        'لطفاً دوباره از طریق ربات اقدام به خرید کنید.'
    );
    exit;
}

Logger::info('Payment callback received', ['trackId' => $trackId]);

// ============================================================
// 2. Call Zibal verify API (NEVER trust GET params alone)
// ============================================================
$zibal = new ZibalService();
$verifyResult = $zibal->verifyPayment($trackId);

if (!$verifyResult['success']) {
    // Verification failed — update payment status to failed if pending
    markPaymentFailed($trackId, $verifyResult['error'] ?? 'Unknown error');

    displayResult(
        false,
        '❌ پرداخت ناموفق',
        'متأسفانه پرداخت شما تأیید نشد.',
        'لطفاً با پشتیبانی تماس بگیرید یا دوباره تلاش کنید.'
    );
    exit;
}

// ============================================================
// 3. Find payment record by track_id
// ============================================================
$payment = findPaymentByTrackId($trackId);

if (!$payment) {
    Logger::error('Payment callback: payment record not found', ['trackId' => $trackId]);
    displayResult(
        false,
        '❌ خطا',
        'اطلاعات تراکنش در سیستم یافت نشد.',
        'لطفاً با پشتیبانی تماس بگیرید.'
    );
    exit;
}

// Extract plan name from payment record
$planName = $payment['plan_id'] ?? null;
if ($planName) {
    try {
        $pStmt = Database::getInstance()->query("SELECT name FROM payment_plans WHERE id = ? OR plan_id = ?", [$planName, $planName]);
        $pRow = $pStmt->fetch();
        if ($pRow) $planName = $pRow['name'];
    } catch (\Throwable $e) {}
}

// ============================================================
// 4. Idempotency — if already verified, do nothing
// ============================================================
if ($payment['status'] === 'verified') {
    Logger::info('Payment callback: already verified (idempotent)', [
        'trackId'  => $trackId,
        'user_id'  => $payment['user_id'],
        'credits'  => $payment['credits'],
    ]);
    displayResult(
        true,
        '✅ پرداخت قبلاً تأیید شده',
        'این پرداخت قبلاً با موفقیت تأیید شده است.',
        'اعتبار شما قبلاً به حساب شما اضافه شده است.'
    );
    exit;
}

// ============================================================
// 5. If pending — verify and add credits
// ============================================================
if ($payment['status'] === 'pending') {
    $userId   = (int) $payment['user_id'];
    $credits  = (int) $payment['credits'];
    $refNumber = $verifyResult['refNumber'] ?? '';

    $db = Database::getInstance();
    $conn = $db->getConnection();

    try {
        // NOTE: CreditService::addCredits() manages its OWN transaction internally.
        // We do NOT wrap in an outer transaction here to avoid nesting conflicts.
        // Payment status update is done first, then credit add is handled separately.

        // 5a. Update payment status to verified
        $stmt = $conn->prepare(
            "UPDATE payments SET status = 'verified', ref_number = ?, verified_at = NOW() WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$refNumber, $payment['id']]);

        if ($stmt->rowCount() === 0) {
            throw new \Exception('Payment record already updated by another process');
        }

        // 5b. Add credits to user (idempotent via reference_id)
        // CreditService::addCredits() handles its own beginTransaction/commit/rollback
        $referenceId = 'purchase_' . $trackId;
        $credited = CreditService::addCredits($userId, $credits, $referenceId);

        if (!$credited) {
            // Rollback payment status manually
            $conn->prepare("UPDATE payments SET status = 'pending', ref_number = NULL, verified_at = NULL WHERE id = ?")->execute([$payment['id']]);
            throw new \Exception('Failed to add credits to user');
        }

        Logger::info('Payment verified and credits added', [
            'trackId'    => $trackId,
            'user_id'    => $userId,
            'credits'    => $credits,
            'refNumber'  => $refNumber,
            'amountRial' => $payment['amount_rial'],
        ]);

        // 5c. Notify user on Bale
        try {
            $stmt = $db->query("SELECT bale_user_id FROM users WHERE id = ?", [$userId]);
            $user = $stmt->fetch();
            if ($user && $user['bale_user_id']) {
                $bale = new BaleClient();
                $planText = $planName ? " برای پلن «{$planName}»" : '';
                $msg = "✅ **پرداخت شما با موفقیت انجام شد!**{$planText}\n\n💎 {$credits} اعتبار به حساب شما اضافه شد.\n\n🙏 از اعتماد شما سپاسگزاریم.";
                $bale->sendMessage((int) $user['bale_user_id'], $msg);
            }
        } catch (\Throwable $e) {
            Logger::error('verify.php: Bale notify failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }

        displayResult(
            true,
            '✅ پرداخت با موفقیت انجام شد',
            "پرداخت شما با موفقیت انجام شد.\n{$credits} اعتبار به حساب شما اضافه شد.",
            'هم‌اکنون می‌توانید به ربات برگشته و از اعتبار خود استفاده کنید.'
        );
        exit;

    } catch (\Throwable $e) {
        Logger::error('Payment callback: transaction failed', [
            'trackId' => $trackId,
            'error'   => $e->getMessage(),
        ]);

        displayResult(
            false,
            '❌ خطا در پردازش',
            'متأسفانه در اضافه کردن اعتبار به حساب شما مشکلی پیش آمد.',
            'لطفاً با پشتیبانی تماس بگیرید و کد تراکنش ' . $trackId . ' را ارائه دهید.'
        );
        exit;
    }

}

// ============================================================
// 6. Unknown status
// ============================================================
Logger::error('Payment callback: unknown payment status', [
    'trackId' => $trackId,
    'status'  => $payment['status'] ?? 'unknown',
]);
displayResult(
    false,
    '❌ خطا',
    'وضعیت تراکنش نامشخص است.',
    'لطفاً با پشتیبانی تماس بگیرید.'
);
exit;

// ============================================================
// Helper Functions
// ============================================================

/**
 * Find payment record by Zibal track_id.
 */
function findPaymentByTrackId(string $trackId): ?array
{
    try {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM payments WHERE track_id = ?", [$trackId]);
        return $stmt->fetch() ?: null;
    } catch (\Throwable $e) {
        Logger::error('verify.php: findPaymentByTrackId failed', [
            'trackId' => $trackId,
            'error'   => $e->getMessage(),
        ]);
        return null;
    }
}

/**
 * Mark payment as failed.
 */
function markPaymentFailed(string $trackId, string $errorMsg): void
{
    try {
        $db = Database::getInstance();
        $db->query(
            "UPDATE payments SET status = 'failed' WHERE track_id = ? AND status = 'pending'",
            [$trackId]
        );
        Logger::info('Payment marked as failed', [
            'trackId' => $trackId,
            'error'   => $errorMsg,
        ]);
    } catch (\Throwable $e) {
        Logger::error('verify.php: markPaymentFailed failed', [
            'trackId' => $trackId,
            'error'   => $e->getMessage(),
        ]);
    }
}


/**
 * Display a simple error page when the entire system fails to load.
 */
function displayErrorPage(string $title, string $details): void
{
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>❌ خطا</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: Tahoma, Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px;
            }
            .card {
                background: white;
                border-radius: 20px;
                padding: 40px;
                max-width: 500px;
                width: 100%;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                text-align: center;
            }
            .icon { font-size: 64px; margin-bottom: 20px; }
            h1 { font-size: 24px; color: #333; margin-bottom: 20px; }
            .message {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
                border-radius: 10px;
                padding: 20px;
                margin-bottom: 20px;
                font-size: 14px;
                line-height: 1.8;
                text-align: left;
                direction: ltr;
                word-break: break-all;
            }
            .btn {
                display: inline-block;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 14px 35px;
                border-radius: 50px;
                text-decoration: none;
                font-size: 16px;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon">❌</div>
            <h1><?php echo htmlspecialchars($title); ?></h1>
            <div class="message"><?php echo nl2br(htmlspecialchars($details)); ?></div>
            <a href="https://t.me/mobix_tube_bot" class="btn">🚀 برگشت به ربات</a>
        </div>
    </body>
    </html>
    <?php
}

/**
 * Display a Persian HTML result page to the user.
 */

function displayResult(bool $success, string $title, string $message, string $footer): void
{
    $icon     = $success ? '✅' : '❌';
    $bgColor  = $success ? '#d4edda' : '#f8d7da';
    $borderColor = $success ? '#c3e6cb' : '#f5c6cb';
    $textColor   = $success ? '#155724' : '#721c24';
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($title); ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: Tahoma, Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px;
            }
            .card {
                background: white;
                border-radius: 20px;
                padding: 40px;
                max-width: 500px;
                width: 100%;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                text-align: center;
            }
            .icon { font-size: 64px; margin-bottom: 20px; }
            h1 {
                font-size: 24px;
                color: #333;
                margin-bottom: 20px;
                border-bottom: 3px solid <?php echo $borderColor; ?>;
                padding-bottom: 15px;
            }
            .message {
                background: <?php echo $bgColor; ?>;
                color: <?php echo $textColor; ?>;
                border: 1px solid <?php echo $borderColor; ?>;
                border-radius: 10px;
                padding: 20px;
                margin-bottom: 20px;
                font-size: 16px;
                line-height: 1.8;
                white-space: pre-line;
                text-align: center;
            }
            .footer {
                color: #666;
                font-size: 14px;
                line-height: 1.8;
                margin-bottom: 25px;
            }
            .btn {
                display: inline-block;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 14px 35px;
                border-radius: 50px;
                text-decoration: none;
                font-size: 16px;
                font-weight: bold;
                transition: transform 0.2s, box-shadow 0.2s;
                box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            }
            .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
            }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon"><?php echo $icon; ?></div>
            <h1><?php echo htmlspecialchars($title); ?></h1>
            <div class="message"><?php echo nl2br(htmlspecialchars($message)); ?></div>
            <div class="footer"><?php echo htmlspecialchars($footer); ?></div>
            <a href="https://t.me/mobix_tube_bot" class="btn">🚀 برگشت به ربات</a>
        </div>
    </body>
    </html>
    <?php
}