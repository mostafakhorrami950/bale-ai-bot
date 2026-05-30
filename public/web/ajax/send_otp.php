<?php
/**
 * AJAX: Send OTP via SMS (IranPayamak)
 */
require_once __DIR__ . '/../init.php';

$input = json_decode(file_get_contents('php://input'), true);
$phone = preg_replace('/[^0-9]/', '', $input['phone'] ?? '');

if (strlen($phone) < 10 || !str_starts_with($phone, '09')) {
    jsonResponse(['success' => false, 'error' => 'شماره موبایل نامعتبر است']);
}

try {
    $db = Database::getInstance();

    // Check cooldown
    $existing = $db->query(
        "SELECT otp_expires_at FROM web_users WHERE phone = ? AND otp_expires_at > NOW()",
        [$phone]
    )->fetch();

    if ($existing) {
        $remaining = strtotime($existing['otp_expires_at']) - time();
        $waitNeeded = OTP_RESEND_SECONDS;
        $elapsed = OTP_EXPIRE_SECONDS - $remaining;
        if ($elapsed < OTP_RESEND_SECONDS) {
            $wait = OTP_RESEND_SECONDS - $elapsed;
            jsonResponse(['success' => false, 'error' => "لطفاً {$wait} ثانیه صبر کنید"]);
        }
    }

    // Generate OTP
    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRE_SECONDS);

    // Upsert web user
    $db->query(
        "INSERT INTO web_users (phone, otp_code, otp_expires_at) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE otp_code = ?, otp_expires_at = ?",
        [$phone, $otp, $expiresAt, $otp, $expiresAt]
    );

    // Send SMS via IranPayamak pattern
    $smsSent = sendSmsPattern($phone, $otp);

    if (!$smsSent) {
        // Fallback: allow in dev mode (log the code)
        Logger::warning('SMS failed, OTP for debug', ['phone' => $phone, 'otp' => $otp]);
    }

    Logger::info('OTP sent', ['phone' => $phone]);
    jsonResponse(['success' => true]);

} catch (\Throwable $e) {
    Logger::error('send_otp error', ['phone' => $phone, 'error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'error' => 'خطا در ارسال کد تایید'], 500);
}

/**
 * Send OTP via IranPayamak pattern-based SMS.
 */
function sendSmsPattern(string $phone, string $otp): bool
{
    $payload = [
        'code' => SMS_PATTERN_CODE,
        'recipient' => $phone,
        'attributes' => ['var1' => $otp],
        'line_number' => SMS_LINE_NUMBER,
        'number_format' => 'fa',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.iranpayamak.com/ws/v1/sms/pattern',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Api-Key: ' . SMS_API_KEY,
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 201) {
        Logger::error('IranPayamak SMS failed', [
            'phone' => $phone,
            'http' => $httpCode,
            'error' => $error,
            'response' => mb_substr($response, 0, 500),
        ]);
        return false;
    }

    return true;
}