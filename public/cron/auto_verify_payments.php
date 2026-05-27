<?php
/**
 * Auto-Verify Pending Zibal Payments
 * 
 * This script checks pending payments that haven't been verified (user didn't
 * return to the callback URL). It queries Zibal's /inquiry endpoint, and if
 * paid, calls /verify to consume the transaction, credits the user, and sends
 * a notification on Bale.
 * 
 * Recommended cron: run every 5 minutes via crontab
 * SAFETY: Idempotent via credit_ledger.reference_id UNIQUE index.
 * SAFETY: Only processes payments created > 2 minutes ago (avoids race with callback).
 */

try {
    require_once __DIR__ . '/../../init.php';
} catch (\Throwable $e) {
    error_log("auto_verify_payments.php: Failed to load init.php: " . $e->getMessage());
    exit(1);
}

use Modules\Payment\ZibalService;
use Modules\Bot\CreditService;
use Modules\Bot\BaleClient;
use Database\Database;
use Database\Logger;

// ---- Configuration ----
$maxAgeSeconds = 120;
$maxPerRun     = 10;
$maxInquiryAge = 3600;

// ---- Execution guard ----
$startTime = microtime(true);

try {
    $db = Database::getInstance();
} catch (\Throwable $e) {
    error_log("auto_verify_payments.php: Database connection failed: " . $e->getMessage());
    exit(1);
}

Logger::info('auto_verify_payments.php: Starting auto-verify cycle');

// ---- Step 1: Find pending payments that need checking ----
try {
    $stmt = $db->query("
        SELECT p.*, u.bale_user_id 
        FROM payments p
        LEFT JOIN users u ON u.id = p.user_id
        WHERE p.status = 'pending'
          AND p.track_id IS NOT NULL
          AND p.track_id != ''
          AND (
              p.last_inquiry_at IS NULL 
              OR p.last_inquiry_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
          )
          AND p.created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
          AND p.created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ORDER BY p.last_inquiry_at ASC NULLS FIRST, p.created_at ASC
        LIMIT ?
    ", [(int)$maxAgeSeconds, (int)$maxInquiryAge, (int)$maxPerRun]);

    $pendingPayments = $stmt->fetchAll();
} catch (\Throwable $e) {
    Logger::error('auto_verify_payments.php: Query failed', ['error' => $e->getMessage()]);
    logAppError('auto_verify_query_failed', $e->getMessage());
    exit(1);
}

if (empty($pendingPayments)) {
    Logger::info('auto_verify_payments.php: No pending payments to check');
    exit(0);
}

Logger::info('auto_verify_payments.php: Found ' . count($pendingPayments) . ' pending payments to check');

$zibal = new ZibalService();
$processed = 0;

foreach ($pendingPayments as $payment) {
    $paymentId = (int)$payment['id'];
    $trackId   = $payment['track_id'];
    $userId    = (int)$payment['user_id'];
    $baleUserId = $payment['bale_user_id'] ? (int)$payment['bale_user_id'] : null;
    $credits   = (int)$payment['credits'];
    $planName  = $payment['plan_id'] ?? '';

    try {
        // ---- Step 2: Mark as checked ----
        $db->query(
            "UPDATE payments SET inquiry_count = inquiry_count + 1, last_inquiry_at = NOW() WHERE id = ?",
            [$paymentId]
        );

        // ---- Step 3: Inquiry with Zibal ----
        $inquiryResult = $zibal->inquiryPayment($trackId);

        if (!$inquiryResult['success']) {
            Logger::warning('auto_verify_payments.php: Inquiry API error', [
                'payment_id' => $paymentId,
                'track_id'   => $trackId,
                'error'      => $inquiryResult['error'] ?? 'unknown',
            ]);
            continue;
        }

        if (!$inquiryResult['paid']) {
            Logger::info('auto_verify_payments.php: Not yet paid', [
                'payment_id' => $paymentId,
                'track_id'   => $trackId,
                'status'     => $inquiryResult['status'] ?? 0,
            ]);
            continue;
        }

        // ---- Step 4: User has paid! Verify ----
        Logger::info('auto_verify_payments.php: Payment detected as paid via inquiry', [
            'payment_id' => $paymentId,
            'track_id'   => $trackId,
            'user_id'    => $userId,
        ]);

        $verifyResult = $zibal->verifyPayment($trackId);

        if (!$verifyResult['success']) {
            $errorMsg = $verifyResult['error'] ?? 'verify failed';
            Logger::error('auto_verify_payments.php: Verify API failed after inquiry showed paid', [
                'payment_id' => $paymentId,
                'track_id'   => $trackId,
                'error'      => $errorMsg,
            ]);
            logAppError('auto_verify_failed', "Payment {$paymentId} (track: {$trackId}) inquiry showed paid but verify failed: {$errorMsg}");
            continue;
        }

        $refNumber = $verifyResult['refNumber'] ?? '';

        // ---- Step 5: Idempotency check ----
        $checkStmt = $db->query("SELECT status FROM payments WHERE id = ?", [$paymentId]);
        $currentPayment = $checkStmt->fetch();
        if (!$currentPayment || $currentPayment['status'] === 'verified') {
            Logger::info('auto_verify_payments.php: Already verified (idempotent)', ['payment_id' => $paymentId]);
            continue;
        }

        // ---- Step 6: Transaction ----
        $conn = $db->getConnection();
        $conn->beginTransaction();

        try {
            $updateStmt = $conn->prepare(
                "UPDATE payments SET status = 'verified', ref_number = ?, verified_at = NOW() WHERE id = ? AND status = 'pending'"
            );
            $updateStmt->execute([$refNumber, $paymentId]);

            if ($updateStmt->rowCount() === 0) {
                throw new \Exception("Payment already updated by another process");
            }

            $referenceId = 'auto_verify_' . $trackId . '_' . time();
            $credited = CreditService::addCredits($userId, $credits, $referenceId);

            if (!$credited) {
                $conn->prepare("UPDATE payments SET status = 'pending', ref_number = NULL, verified_at = NULL WHERE id = ?")
                    ->execute([$paymentId]);
                throw new \Exception("Failed to add credits to user {$userId}");
            }

            $conn->commit();

            // ---- Step 7: Notify user on Bale ----
            if ($baleUserId) {
                try {
                    $bale = new BaleClient();
                    $planDisplay = $planName;
                    try {
                        $pStmt = $db->query("SELECT name FROM payment_plans WHERE id = ? OR plan_id = ?", [$planName, $planName]);
                        $pRow = $pStmt->fetch();
                        if ($pRow) $planDisplay = $pRow['name'];
                    } catch (\Throwable $ignored) {}

                    $planText = $planDisplay ? " برای پلن «{$planDisplay}»" : '';
                    $msg = "✅ **پرداخت شما با موفقیت انجام شد!**{$planText}\n\n"
                         . "💎 {$credits} اعتبار به حساب شما اضافه شد.\n"
                         . "📄 کد پیگیری: {$refNumber}\n\n"
                         . "🙏 از اعتماد شما سپاسگزاریم.\n\n"
                         . "👈 اکنون می‌توانید از ربات استفاده کنید.";

                    $bale->sendMessage($baleUserId, $msg);
                    Logger::info('auto_verify_payments.php: User notified on Bale', [
                        'payment_id' => $paymentId,
                        'bale_user_id' => $baleUserId,
                    ]);
                } catch (\Throwable $e) {
                    Logger::error('auto_verify_payments.php: Failed to notify user', [
                        'payment_id' => $paymentId,
                        'bale_user_id' => $baleUserId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $processed++;
            Logger::info('auto_verify_payments.php: Payment auto-verified successfully', [
                'payment_id' => $paymentId,
                'track_id'   => $trackId,
                'user_id'    => $userId,
                'credits'    => $credits,
                'ref_number' => $refNumber,
            ]);

        } catch (\Throwable $e) {
            $conn->rollBack();
            Logger::error('auto_verify_payments.php: Transaction failed', [
                'payment_id' => $paymentId,
                'track_id'   => $trackId,
                'error'      => $e->getMessage(),
            ]);
            logAppError('auto_verify_transaction_failed', "Payment {$paymentId}: " . $e->getMessage());
        }

    } catch (\Throwable $e) {
        Logger::error('auto_verify_payments.php: Payment processing error', [
            'payment_id' => $paymentId,
            'track_id'   => $trackId,
            'error'      => $e->getMessage(),
        ]);
        logAppError('auto_verify_processing_error', "Payment {$paymentId} (track: {$trackId}): " . $e->getMessage());
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
Logger::info('auto_verify_payments.php: Cycle completed', [
    'checked'   => count($pendingPayments),
    'processed' => $processed,
    'elapsed_s' => $elapsed,
]);

exit(0);

// ============================================================
// Helper
// ============================================================
function logAppError(string $errorType, string $errorMessage): void
{
    try {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO app_errors (error_type, error_message) VALUES (?, ?)",
            [$errorType, $errorMessage]
        );
    } catch (\Throwable $ignored) {}
}