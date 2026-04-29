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
        $modelConfig = $data['model_config'] ?? '{}';
        if (is_array($modelConfig)) {
            $modelConfig = json_encode($modelConfig, JSON_UNESCAPED_UNICODE);
        }
        $costPerInput = (float)($data['cost_per_input_char'] ?? 0.000001);
        $costPerOutput = (float)($data['cost_per_output_char'] ?? 0.000002);
        $freeModel = isset($data['free_model']) ? (int)$data['free_model'] : 0;
        $modelType = $data['model_type'] ?? 'image_generation';
        $sql = "INSERT INTO ai_models (name, provider, model_type, cost_per_image, is_active, model_config, cost_per_input_char, cost_per_output_char, free_model) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        return $this->db->query($sql, [
            $data['name'],
            $data['provider'] ?? 'gapgpt',
            $modelType,
            (int)$data['cost_per_image'],
            isset($data['is_active']) ? (int)$data['is_active'] : 1,
            $modelConfig,
            $costPerInput,
            $costPerOutput,
            $freeModel,
        ]);
    }

    public function updateModel($id, $data)
    {
        $this->validate($data);
        $modelConfig = $data['model_config'] ?? '{}';
        if (is_array($modelConfig)) {
            $modelConfig = json_encode($modelConfig, JSON_UNESCAPED_UNICODE);
        }
        $costPerInput = (float)($data['cost_per_input_char'] ?? 0.000001);
        $costPerOutput = (float)($data['cost_per_output_char'] ?? 0.000002);
        $freeModel = isset($data['free_model']) ? (int)$data['free_model'] : 0;
        $modelType = $data['model_type'] ?? 'image_generation';
        $sql = "UPDATE ai_models SET name = ?, provider = ?, model_type = ?, cost_per_image = ?, is_active = ?, model_config = ?, cost_per_input_char = ?, cost_per_output_char = ?, free_model = ? WHERE id = ?";
        return $this->db->query($sql, [
            $data['name'],
            $data['provider'] ?? 'gapgpt',
            $modelType,
            (int)$data['cost_per_image'],
            (int)$data['is_active'],
            $modelConfig,
            $costPerInput,
            $costPerOutput,
            $freeModel,
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