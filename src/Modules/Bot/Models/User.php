<?php

namespace Modules\Bot\Models;

use Database\Database;
use Database\Logger;
use Core\Config;
use PDO;

class User
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getByBaleId(int $telegramId)
    {
        $stmt = $this->db->query("SELECT * FROM users WHERE telegram_id = ?", [$telegramId]);
        return $stmt->fetch();
    }

    /**
     * Register a user. Inserts if not exists, updates if exists.
     * M1: Added INSERT for new users (was UPDATE-only).
     * M3: Wrapped in try-catch, returns false on failure.
     */
    public function register(int $telegramId, array $data): bool
    {
        try {
            $user = self::findByBaleId($telegramId);
            
            if ($user) {
                // User exists — UPDATE
                $sql = "UPDATE users SET 
                        phone_number = ?, 
                        first_name = ?, 
                        last_name = ?, 
                        is_registered = 1, 
                        last_active_at = CURRENT_TIMESTAMP 
                        WHERE telegram_id = ?";
                $this->db->query($sql, [
                    $data['phone_number'],
                    $data['first_name'],
                    $data['last_name'],
                    $telegramId
                ]);
            } else {
                // M1: New user — INSERT with initial credits from config
                $initialCredits = (float)(Config::get('FREE_CREDITS_ON_START', 15));
                $sql = "INSERT INTO users (telegram_id, phone_number, first_name, last_name, is_registered, credits) 
                        VALUES (?, ?, ?, ?, 1, ?)";
                $this->db->query($sql, [
                    $telegramId,
                    $data['phone_number'],
                    $data['first_name'],
                    $data['last_name'],
                    $initialCredits
                ]);
            }
            
            return true;
        } catch (\Throwable $e) {
            Logger::error('User::register failed', [
                'telegram_id' => $telegramId,
                'error'   => $e->getMessage()
            ]);
            return false;
        }
    }

    public static function findByBaleId(int $telegramId)
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM users WHERE telegram_id = ?", [$telegramId]);
        return $stmt->fetch();
    }

    /**
     * Create or update user from /start or webhook data.
     * I2: Ensure is_registered = 1 if phone_number is present.
     */
    public static function createOrUpdate(array $data)
    {
        $db = Database::getInstance();
        $user = self::findByBaleId($data['telegram_id']);
        $initialCredits = (float)(Config::get('FREE_CREDITS_ON_START', 15));

        if ($user) {
            $sql = "UPDATE users SET 
                    first_name = ?, 
                    last_name = ?, 
                    username = ?, 
                    phone_number = COALESCE(?, phone_number), 
                    is_registered = CASE WHEN ? IS NOT NULL OR is_registered = 1 THEN 1 ELSE 0 END,
                    last_active_at = CURRENT_TIMESTAMP 
                    WHERE telegram_id = ?";
            $db->query($sql, [
                $data['first_name'],
                $data['last_name'],
                $data['username'],
                $data['phone_number'] ?? null,
                $data['phone_number'] ?? null,
                $data['telegram_id']
            ]);
            return self::findByBaleId($data['telegram_id']);
        } else {
            $isRegistered = ($data['phone_number'] ?? null) ? 1 : 0;
            $sql = "INSERT INTO users (telegram_id, first_name, last_name, username, phone_number, is_registered, credits) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $db->query($sql, [
                $data['telegram_id'],
                $data['first_name'],
                $data['last_name'],
                $data['username'],
                $data['phone_number'] ?? null,
                $isRegistered,
                $initialCredits
            ]);
            return self::findByBaleId($data['telegram_id']);
        }
    }

    public static function isRegistered(int $telegramId): bool
    {
        $user = self::findByBaleId($telegramId);
        return $user && $user['is_registered'] == 1;
    }

    public static function updateCredits(int $userId, float $amount, string $type, string $description, string $referenceId = null)
    {
        $db = Database::getInstance();
        $pdo = $db->pdo();
        
        try {
            $pdo->beginTransaction();
            
            // Update user credits
            $sql = "UPDATE users SET credits = credits + (?) WHERE id = ?";
            $db->query($sql, [$amount, $userId]);
            
            // Log in ledger
            $sql = "INSERT INTO credit_ledger (user_id, amount, type, description, reference_id) VALUES (?, ?, ?, ?, ?)";
            $db->query($sql, [$userId, $amount, $type, $description, $referenceId]);
            
            $pdo->commit();
            return true;
        } catch (\Exception $e) {
            $pdo->rollBack();
            return false;
        }
    }
}