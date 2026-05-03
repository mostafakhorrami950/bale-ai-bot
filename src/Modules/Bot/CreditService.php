<?php

namespace Modules\Bot;

use Database\Database;
use Database\Logger;

class CreditService
{
    /**
     * Get current credit balance for a user (returns float for decimal precision).
     */
    public static function getBalance(int $userId): float
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT credits FROM users WHERE id = ?", [$userId]);
            $row = $stmt->fetch();
            return $row ? (float) $row['credits'] : 0.0;
        } catch (\Throwable $e) {
            Logger::error('CreditService::getBalance failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /**
     * Check if user has enough credit (float comparison).
     */
    public static function hasEnoughCredit(int $userId, float $cost): bool
    {
        return self::getBalance($userId) >= $cost - 0.0000000001; // tolerance for floating point (10 decimal precision)
    }

    /**
     * Deduct credits from user. Idempotent — checks reference_id before inserting.
     * Supports decimal amounts for per-character billing.
     *
     * @param int    $userId      User ID from users table
     * @param float  $amount      Credits to deduct (decimal supported)
     * @param string $referenceId Unique reference for idempotency
     *
     * @return bool  true on success, false on failure
     */
    public static function deduct(int $userId, float $amount, string $referenceId): bool
    {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        try {
            $conn->beginTransaction();

            // 1. Check idempotency
            $stmt = $conn->prepare("SELECT id FROM credit_ledger WHERE reference_id = ?");
            $stmt->execute([$referenceId]);
            if ($stmt->fetch()) {
                $conn->commit();
                return true; // idempotent
            }

            // 2. Deduct credits with decimal support (atomic: only if sufficient)
            $stmt = $conn->prepare("UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ? - 0.0000000001");
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
     * Refund/credit back previously deducted credits. Idempotent.
     * Logged as 'credit_back' type in ledger for traceability.
     */
    public static function creditBack(int $userId, float $amount, string $referenceId): bool
    {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT id FROM credit_ledger WHERE reference_id = ?");
            $stmt->execute([$referenceId]);
            if ($stmt->fetch()) {
                $conn->commit();
                return true;
            }

            $stmt = $conn->prepare("UPDATE users SET credits = credits + ? WHERE id = ?");
            $stmt->execute([$amount, $userId]);

            if ($stmt->rowCount() === 0) {
                throw new \Exception("User not found");
            }

            $stmt = $conn->prepare(
                "INSERT INTO credit_ledger (user_id, amount, type, reference_id) VALUES (?, ?, 'credit_back', ?)"
            );
            $stmt->execute([$userId, $amount, $referenceId]);

            $conn->commit();
            Logger::info('CreditService::creditBack success', [
                'user_id' => $userId, 'amount' => $amount, 'reference_id' => $referenceId
            ]);
            return true;
        } catch (\Throwable $e) {
            $conn->rollBack();
            Logger::error('CreditService::creditBack failed', [
                'user_id' => $userId, 'amount' => $amount, 'reference_id' => $referenceId, 'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Add credits to user. Idempotent.
     */
    public static function addCredits(int $userId, float $amount, string $referenceId): bool
    {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT id FROM credit_ledger WHERE reference_id = ?");
            $stmt->execute([$referenceId]);
            if ($stmt->fetch()) {
                $conn->commit();
                return true;
            }

            $stmt = $conn->prepare("UPDATE users SET credits = credits + ? WHERE id = ?");
            $stmt->execute([$amount, $userId]);

            if ($stmt->rowCount() === 0) {
                throw new \Exception("User not found");
            }

            $stmt = $conn->prepare(
                "INSERT INTO credit_ledger (user_id, amount, type, reference_id) VALUES (?, ?, 'charge', ?)"
            );
            $stmt->execute([$userId, $amount, $referenceId]);

            $conn->commit();
            return true;
        } catch (\Throwable $e) {
            $conn->rollBack();
            Logger::error('CreditService::addCredits failed', [
                'user_id' => $userId, 'amount' => $amount, 'reference_id' => $referenceId, 'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}