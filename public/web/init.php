<?php
/**
 * Web version bootstrap
 * Loads bot init.php first, then sets up web-specific features.
 */
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/config.php';

use Database\Database;
use Database\Logger;

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get currently logged in web user.
 * Returns user array from web_users table, or null if not logged in.
 */
function getWebUser(): ?array
{
    if (empty($_SESSION['web_user_id'])) {
        return null;
    }
    try {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM web_users WHERE id = ? AND is_active = 1", [(int)$_SESSION['web_user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            unset($_SESSION['web_user_id']);
            return null;
        }
        return $user;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Require authentication — redirects to login if not authenticated.
 * Returns the authenticated user array.
 */
function requireAuth(): array
{
    $user = getWebUser();
    if (!$user) {
        header('Location: /');
        exit;
    }
    return $user;
}

/**
 * Get the linked bot user_id for a web user.
 * Web users are linked to bot users via phone_number.
 */
function getBotUserId(int $webUserId): ?int
{
    try {
        $db = Database::getInstance();
        $webUser = $db->query("SELECT phone, bale_user_id FROM web_users WHERE id = ?", [$webUserId])->fetch();
        if (!$webUser) return null;
        
        if (!empty($webUser['bale_user_id'])) {
            $botUser = $db->query("SELECT id FROM users WHERE bale_user_id = ?", [(int)$webUser['bale_user_id']])->fetch();
            if ($botUser) return (int)$botUser['id'];
        }
        
        // Try to find by phone number
        $botUser = $db->query("SELECT id FROM users WHERE phone_number = ?", [$webUser['phone']])->fetch();
        return $botUser ? (int)$botUser['id'] : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Helper: Output JSON and exit.
 */
function jsonResponse(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Helper: Redirect with message.
 */
function redirect(string $url, ?string $msg = null, string $type = 'success'): void
{
    if ($msg) {
        $_SESSION['flash_' . $type] = $msg;
    }
    header('Location: ' . $url);
    exit;
}