<?php

namespace Modules\Bot\Models;

use Database\Database;
use Database\Logger;
use Core\Config;

class User
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get user by Bale user ID.
     */
    public function getByBaleId(int $baleUserId)
    {
        $stmt = $this->db->query("SELECT * FROM users WHERE bale_user_id = ?", [$baleUserId]);
        return $stmt->fetch();
    }

    /**
     * Register a user. Inserts if not exists, updates if exists.
     */
    public function register(int $baleUserId, array $data): bool
    {
        try {
            $user = self::findByBaleId($baleUserId);

            if ($user) {
                // User exists — UPDATE
                $sql = "UPDATE users SET
                        phone_number = ?,
                        is_registered = 1,
                        last_active_at = CURRENT_TIMESTAMP
                        WHERE bale_user_id = ?";
                $this->db->query($sql, [
                    $data['phone_number'],
                    $baleUserId
                ]);
                // Update profile info in user_profiles (always, even if empty)
                $this->db->query(
                    "INSERT INTO user_profiles (user_id, first_name, last_name, username)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), username = VALUES(username)",
                    [$user['id'], $data['first_name'] ?? '', $data['last_name'] ?? '', $data['username'] ?? '']
                );
            } else {
                // New user — INSERT
                $initialCredits = (float)(Config::get('FREE_CREDITS_ON_START', 15));
                $sql = "INSERT INTO users (bale_user_id, phone_number, is_registered, credits)
                        VALUES (?, ?, 1, ?)";
                $this->db->query($sql, [
                    $baleUserId,
                    $data['phone_number'],
                    $initialCredits
                ]);
                // Get the new user ID and insert profile
                $stmt = $this->db->query("SELECT id FROM users WHERE bale_user_id = ?", [$baleUserId]);
                $newUser = $stmt->fetch();
                if ($newUser) {
                    $this->db->query(
                        "INSERT INTO user_profiles (user_id, first_name, last_name, username) VALUES (?, ?, ?, ?)",
                        [$newUser['id'], $data['first_name'] ?? '', $data['last_name'] ?? '', $data['username'] ?? '']
                    );
                }
            }

            return true;
        } catch (\Throwable $e) {
            Logger::error('User::register failed', [
                'bale_user_id' => $baleUserId,
                'error'   => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Find user by their Bale user ID.
     */
    public static function findByBaleId(int $baleUserId)
    {
        $db = Database::getInstance();
        $stmt = $db->query("
            SELECT u.*, up.first_name, up.last_name
            FROM users u
            LEFT JOIN user_profiles up ON up.user_id = u.id
            WHERE u.bale_user_id = ?
        ", [$baleUserId]);
        return $stmt->fetch();
    }

    /**
     * Check if a user is registered.
     */
    public static function isRegistered(int $baleUserId): bool
    {
        $user = self::findByBaleId($baleUserId);
        return $user && $user['is_registered'] == 1;
    }
}
