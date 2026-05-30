<?php
/**
 * AJAX: Verify OTP and login
 */
require_once __DIR__ . '/../init.php';

$input = json_decode(file_get_contents('php://input'), true);
$phone = preg_replace('/[^0-9]/', '', $input['phone'] ?? '');
$code  = preg_replace('/[^0-9]/', '', $input['code'] ?? '');

if (strlen($phone) < 10 || strlen($code) !== 6) {
    jsonResponse(['success' => false, 'error' => 'اطلاعات نامعتبر است']);
}

try {
    $db = Database::getInstance();

    // Find web user by phone and valid OTP
    $stmt = $db->query(
        "SELECT * FROM web_users WHERE phone = ? AND otp_code = ? AND otp_expires_at > NOW() AND is_active = 1",
        [$phone, $code]
    );
    $webUser = $stmt->fetch();

    if (!$webUser) {
        jsonResponse(['success' => false, 'error' => 'کد نامعتبر یا منقضی شده است']);
    }

    // Clear OTP
    $db->query("UPDATE web_users SET otp_code = NULL, otp_expires_at = NULL, last_login = NOW() WHERE id = ?", [$webUser['id']]);

    // Link to bot user by phone if not already linked
    if (empty($webUser['bale_user_id'])) {
        $botUser = $db->query("SELECT id, bale_user_id FROM users WHERE phone_number = ?", [$phone])->fetch();
        if ($botUser) {
            $db->query("UPDATE web_users SET bale_user_id = ? WHERE id = ?", [$botUser['bale_user_id'], $webUser['id']]);
            $webUser['bale_user_id'] = $botUser['bale_user_id'];
        } else {
            // Create bot user record if not exists (with default bale_user_id to avoid NULL FK issues)
            $existingById = $db->query("SELECT id FROM users WHERE bale_user_id = 0 AND phone_number = ?", [$phone])->fetch();
            if (!$existingById) {
                $db->query(
                    "INSERT INTO users (bale_user_id, phone_number, first_name, is_registered, credits) VALUES (0, ?, 'کاربر وب', 1, ?)",
                    [$phone, 15] // initial credit from settings
                );
                $newBotUserId = (int)$db->lastInsertId();
                $db->query("UPDATE web_users SET bale_user_id = 0 WHERE id = ?", [$webUser['id']]);
                $webUser['bale_user_id'] = 0;
            }
        }
    }

    // Set session
    $_SESSION['web_user_id'] = (int)$webUser['id'];

    Logger::info('Web user logged in', ['web_user_id' => $webUser['id'], 'phone' => $phone]);
    jsonResponse(['success' => true]);

} catch (\Throwable $e) {
    Logger::error('verify_otp error', ['phone' => $phone, 'error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'error' => 'خطا در تایید کد'], 500);
}