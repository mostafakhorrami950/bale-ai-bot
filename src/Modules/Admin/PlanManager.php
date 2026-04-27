<?php

namespace Modules\Admin;

use Database\Database;

class PlanManager
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getAll(): array
    {
        return $this->db->query("SELECT * FROM payment_plans ORDER BY price_rial ASC")->fetchAll();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->query("SELECT * FROM payment_plans WHERE id = ?", [$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): bool
    {
        $this->validate($data);
        $sql = "INSERT INTO payment_plans (plan_id, name, credits, price_rial, is_active) VALUES (?, ?, ?, ?, ?)";
        $this->db->query($sql, [
            $data['plan_id'],
            $data['name'],
            (int) $data['credits'],
            (int) $data['price_rial'],
            isset($data['is_active']) ? (int) $data['is_active'] : 1,
        ]);
        return true;
    }

    public function update(int $id, array $data): bool
    {
        $this->validate($data);
        $sql = "UPDATE payment_plans SET name = ?, credits = ?, price_rial = ?, is_active = ? WHERE id = ?";
        $this->db->query($sql, [
            $data['name'],
            (int) $data['credits'],
            (int) $data['price_rial'],
            isset($data['is_active']) ? (int) $data['is_active'] : 0,
            $id,
        ]);
        return true;
    }

    public function delete(int $id): bool
    {
        $this->db->query("DELETE FROM payment_plans WHERE id = ?", [$id]);
        return true;
    }

    public function toggleActive(int $id): bool
    {
        $this->db->query("UPDATE payment_plans SET is_active = 1 - is_active WHERE id = ?", [$id]);
        return true;
    }

    private function validate(array $data): void
    {
        if (empty($data['name'])) {
            throw new \Exception("نام پلن الزامی است.");
        }
        if (!isset($data['credits']) || (int) $data['credits'] <= 0) {
            throw new \Exception("تعداد اعتبار باید عدد مثبت باشد.");
        }
        if (!isset($data['price_rial']) || (int) $data['price_rial'] <= 0) {
            throw new \Exception("قیمت باید عدد مثبت باشد.");
        }
    }
}