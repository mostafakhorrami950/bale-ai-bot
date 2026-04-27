<?php

namespace Modules\Bot;

use Database\Database;
use Database\Logger;

/**
 * N5: Simple DB-based rate limiter for anti-spam protection.
 *
 * Limits:
 * - /start: max 5 per 60 seconds per user
 * - image generation: max 10 per 60 seconds per user
 * - admin login: max 5 per 60 seconds per IP
 */
class RateLimiter
{
    /**
     * Check if an action is rate-limited.
     *
     * @param string $key   Unique key (e.g. "start:123456", "admin_login:192.168.1.1")
     * @param int    $limit Max allowed requests
     * @param int    $window Time window in seconds
     *
     * @return bool  True if NOT rate-limited (can proceed), False if blocked
     */
    public static function check(string $key, int $limit = 5, int $window = 60): bool
    {
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            $table = 'rate_limits';

            // Ensure table exists
            $conn->exec("
                CREATE TABLE IF NOT EXISTS $table (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    action_key VARCHAR(255) NOT NULL,
                    action_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_action_key (action_key),
                    INDEX idx_action_time (action_time)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Clean old entries
            $conn->exec("DELETE FROM $table WHERE action_time < DATE_SUB(NOW(), INTERVAL $window SECOND)");

            // Count recent actions
            $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM $table WHERE action_key = ? AND action_time > DATE_SUB(NOW(), INTERVAL $window SECOND)");
            $stmt->execute([$key]);
            $row = $stmt->fetch();

            $count = $row ? (int) $row['cnt'] : 0;

            if ($count >= $limit) {
                Logger::warning("Rate limit exceeded for: $key ($count/$limit in {$window}s)");
                return false; // Blocked
            }

            // Record this attempt
            $stmt = $conn->prepare("INSERT INTO $table (action_key) VALUES (?)");
            $stmt->execute([$key]);

            return true; // Allowed
        } catch (\Throwable $e) {
            Logger::error('RateLimiter::check failed', [
                'key'   => $key,
                'error' => $e->getMessage()
            ]);
            return true; // Fail open — allow if DB error
        }
    }
}