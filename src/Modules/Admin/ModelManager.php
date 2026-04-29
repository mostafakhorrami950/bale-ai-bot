<?php

namespace Modules\Admin;

use Database\Database;

class ModelManager
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getAllModels()
    {
        return $this->db->query("SELECT * FROM ai_models ORDER BY created_at DESC")->fetchAll();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->query("SELECT * FROM ai_models WHERE id = ?", [$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createModel($data)
    {
        $this->validate($data);
        $sql = "INSERT INTO ai_models (name, provider, cost_per_image, is_active) VALUES (?, ?, ?, ?)";
        return $this->db->query($sql, [
            $data['name'],
            $data['provider'] ?? 'gapgpt',
            (int)$data['cost_per_image'],
            isset($data['is_active']) ? (int)$data['is_active'] : 1
        ]);
    }

    public function updateModel($id, $data)
    {
        $this->validate($data);
        $sql = "UPDATE ai_models SET name = ?, provider = ?, cost_per_image = ?, is_active = ? WHERE id = ?";
        return $this->db->query($sql, [
            $data['name'],
            $data['provider'] ?? 'gapgpt',
            (int)$data['cost_per_image'],
            (int)$data['is_active'],
            (int)$id
        ]);
    }

    public function toggleModel($id)
    {
        $sql = "UPDATE ai_models SET is_active = 1 - is_active WHERE id = ?";
        return $this->db->query($sql, [(int)$id]);
    }

    public function deleteModel($id)
    {
        $sql = "DELETE FROM ai_models WHERE id = ?";
        return $this->db->query($sql, [(int)$id]);
    }

    private function validate($data)
    {
        if (empty($data['name'])) {
            throw new \Exception("نام مدل الزامی است.");
        }
        if (!isset($data['cost_per_image']) || !is_numeric($data['cost_per_image'])) {
            throw new \Exception("هزینه تصویر باید عدد باشد.");
        }
    }
}