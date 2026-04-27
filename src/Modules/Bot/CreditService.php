<?php

namespace Modules\Bot;

use Database\Database;
use Database\Logger;

class CreditService
{
    /**
     * Get current credit balance for a user.
     */
    public static function getBalance(int $userId): int
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT credits FROM users WHERE id = ?", [$userId]);
            $row = $stmt->fetch();
            return $row ? (int) $row['credits'] : 0;
        } catch (\Throwable $e) {
            Logger::error('CreditService::getBalance failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Check if user has enough credit.
     */
    public static function hasEnoughCredit(int $userId, int $cost): bool
    {
        return self::getBalance($userId) >= $cost;
    }

    /**
     * Check balance with a single query (legacy compatibility).
     */
    public static function checkBalance(int $userId, int $requiredCredits): bool
    {
        return self::hasEnoughCredit($userId, $requiredCredits);
    }

    /**
     * Deduct credits from user. Idempotent — checks reference_id before inserting.
     *
     * @param int    $userId      User ID from users table
     * @param int    $amount      Credits to deduct
     * @param string $referenceId Unique reference for idempotency (e.g. "ai_req_123")
     *
     * @return bool  true on success, false on failure
     */
    public static function deduct(int $userId, int $amount, string $referenceId): bool
    {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        try {
            $conn->beginTransaction();

            // 1. Check idempotency — if reference already exists, skip
            $stmt = $conn->prepare("SELECT id FROM credit_ledger WHERE reference_id = ?");
            $stmt->execute([$referenceId]);
            if ($stmt->fetch()) {
                // Already processed — return success silently (idempotent)
                $conn->commit();
                return true;
            }

            // 2. Deduct credits (atomic: only if sufficient)
            $stmt = $conn->prepare("UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?");
            $stmt->execute([$amount, $userId, $amount]);

            if ($stmt->rowCount() === 0) {
                throw new \Exception("Insufficient balance or user not found");
            }

            // 3. Log to credit_ledger
            $stmt = $conn->prepare(
                "INSERT INTO credit_ledger (user_id, amount, type, reference_id) VALUES (?, ?, 'deduction', ?)"
            );
            $stmt->execute([$userId, $amount, $referenceId]);

            $conn->commit();
            Logger::info('CreditService::deduct success', [
                'user_id'      => $userId,
                'amount'       => $amount,
                'reference_id' => $referenceId,
            ]);
            return true;
        } catch (\Throwable $e) {
            $conn->rollBack();
            Logger::error('CreditService::deduct failed', [
                'user_id'      => $userId,
                'amount'       => $amount,
                'reference_id' => $referenceId,
                'error'        => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Add credits to user. Idempotent — checks reference_id before inserting.
     *
     * @param int    $userId      User ID from users table
     * @param int    $amount      Credits to add (positive integer)
     * @param string $referenceId Unique reference for idempotency (e.g. "purchase_trackId")
     *
     * @return bool  true on success, false on failure
     */
    public static function addCredits(int $userId, int $amount, string $referenceId): bool
    {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        try {
            $conn->beginTransaction();

            // 1. Check idempotency — if reference already exists, skip
            $stmt = $conn->prepare("SELECT id FROM credit_ledger WHERE reference_id = ?");
            $stmt->execute([$referenceId]);
            if ($stmt->fetch()) {
                // Already processed — return success silently (idempotent)
                $conn->commit();
                return true;
            }

            // 2. Add credits
            $stmt = $conn->prepare("UPDATE users SET credits = credits + ? WHERE id = ?");
            $stmt->execute([$amount, $userId]);

            if ($stmt->rowCount() === 0) {
                throw new \Exception("User not found");
            }

            // 3. Log to credit_ledger
            $stmt = $conn->prepare(
                "INSERT INTO credit_ledger (user_id, amount, type, reference_id) VALUES (?, ?, 'charge', ?)"
            );
            $stmt->execute([$userId, $amount, $referenceId]);

            $conn->commit();
            Logger::info('CreditService::addCredits success', [
                'user_id'      => $userId,
                'amount'       => $amount,
                'reference_id' => $referenceId,
            ]);
            return true;
        } catch (\Throwable $e) {
            $conn->rollBack();
            Logger::error('CreditService::addCredits failed', [
                'user_id'      => $userId,
                'amount'       => $amount,
                'reference_id' => $referenceId,
                'error'        => $e->getMessage(),
            ]);
            return false;
        }
    }
}