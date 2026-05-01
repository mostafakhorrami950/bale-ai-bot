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

    // ─────────────────────────────────────────────────────────────
    //   Unified CRUD — reads/writes all model tables separately
    // ─────────────────────────────────────────────────────────────

    /**
     * Get all models from all type tables (for admin list).
     */
    public function getAllModels(): array
    {
        $all = [];

        // Image generation
        $rows = $this->db->query("SELECT id, name, provider, cost_per_image AS cost, model_config, is_active, created_at, 'image_generation' AS model_type FROM ai_image_models ORDER BY created_at DESC")->fetchAll();
        foreach ($rows as $r) { $r['cost_label'] = $r['cost'] . ' اعتبار'; $all[] = $r; }

        // Image editing
        $rows = $this->db->query("SELECT id, name, provider, cost_per_edit AS cost, model_config, is_active, created_at, 'image_editing' AS model_type FROM ai_edit_models ORDER BY created_at DESC")->fetchAll();
        foreach ($rows as $r) { $r['cost_label'] = $r['cost'] . ' اعتبار'; $all[] = $r; }

        // Text models
        $rows = $this->db->query("SELECT id, name, provider, cost_per_input_char AS cost, cost_per_output_char, free_model, model_config, is_active, created_at, 'text' AS model_type FROM ai_text_models ORDER BY created_at DESC")->fetchAll();
        foreach ($rows as $r) { 
            $r['cost_label'] = $r['free_model'] ? '🆓 رایگان' : ($r['cost'] . '/' . ($r['cost_per_output_char'] ?? 0) . ' هر کاراکتر'); 
            $all[] = $r; 
        }

        // Video models
        $rows = $this->db->query("SELECT id, name, provider, cost_per_video AS cost, model_config, is_active, created_at, 'video' AS model_type FROM ai_video_models ORDER BY created_at DESC")->fetchAll();
        foreach ($rows as $r) { $r['cost_label'] = $r['cost'] . ' اعتبار'; $all[] = $r; }

        // Sort by created_at DESC
        usort($all, function($a, $b) { return strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''); });
        return $all;
    }

    /**
     * Get a model by ID from the appropriate type table.
     */
    public function getById(int $id): ?array
    {
        // Check image models first
        $stmt = $this->db->query("SELECT *, 'image_generation' AS model_type FROM ai_image_models WHERE id = ?", [$id]);
        $row = $stmt->fetch();
        if ($row) return $this->normalizeRow($row, 'image_generation');

        $stmt = $this->db->query("SELECT *, 'image_editing' AS model_type FROM ai_edit_models WHERE id = ?", [$id]);
        $row = $stmt->fetch();
        if ($row) return $this->normalizeRow($row, 'image_editing');

        $stmt = $this->db->query("SELECT *, 'text' AS model_type FROM ai_text_models WHERE id = ?", [$id]);
        $row = $stmt->fetch();
        if ($row) return $this->normalizeRow($row, 'text');

        $stmt = $this->db->query("SELECT *, 'video' AS model_type FROM ai_video_models WHERE id = ?", [$id]);
        $row = $stmt->fetch();
        if ($row) return $this->normalizeRow($row, 'video');

        return null;
    }

    private function normalizeRow(array $row, string $type): array
    {
        // Map type-specific cost to cost_per_image for backward compat
        if (isset($row['cost_per_image'])) {
            // already set
        } elseif (isset($row['cost_per_edit'])) {
            $row['cost_per_image'] = $row['cost_per_edit'];
        } elseif (isset($row['cost_per_video'])) {
            $row['cost_per_image'] = $row['cost_per_video'];
        } else {
            $row['cost_per_image'] = 0;
        }
        $row['model_type'] = $type;
        return $row;
    }

    /**
     * Create a model in the appropriate table based on model_type.
     */
    public function createModel(array $data): void
    {
        $modelType = $data['model_type'] ?? 'image_generation';

        switch ($modelType) {
            case 'text':
                $name = trim($data['name']);
                if (empty($name)) throw new \Exception("نام مدل الزامی است");
                $this->db->query(
                    "INSERT INTO ai_text_models (name, provider, cost_per_input_char, cost_per_output_char, free_model, model_config, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $name,
                        $data['provider'] ?? 'openrouter',
                        (float)($data['cost_per_input_char'] ?? 0.000001),
                        (float)($data['cost_per_output_char'] ?? 0.000002),
                        isset($data['free_model']) ? (int)$data['free_model'] : 0,
                        ($data['model_config'] ?? '{}'),
                        isset($data['is_active']) ? (int)$data['is_active'] : 1
                    ]
                );
                break;

            case 'image_editing':
                $name = trim($data['name']);
                if (empty($name)) throw new \Exception("نام مدل الزامی است");
                $cost = (int)($data['cost_per_image'] ?? 2);
                if ($cost < 1) throw new \Exception("هزینه باید حداقل ۱ باشد");
                $this->db->query(
                    "INSERT INTO ai_edit_models (name, provider, cost_per_edit, model_config, is_active) VALUES (?, ?, ?, ?, ?)",
                    [
                        $name,
                        $data['provider'] ?? 'gapgpt',
                        $cost,
                        ($data['model_config'] ?? '{}'),
                        isset($data['is_active']) ? (int)$data['is_active'] : 1
                    ]
                );
                break;

            case 'video':
                $name = trim($data['name']);
                if (empty($name)) throw new \Exception("نام مدل الزامی است");
                $cost = (int)($data['cost_per_image'] ?? 5);
                if ($cost < 1) throw new \Exception("هزینه باید حداقل ۱ باشد");
                $this->db->query(
                    "INSERT INTO ai_video_models (name, provider, cost_per_video, model_config, is_active) VALUES (?, ?, ?, ?, ?)",
                    [
                        $name,
                        $data['provider'] ?? 'gapgpt',
                        $cost,
                        ($data['model_config'] ?? '{}'),
                        isset($data['is_active']) ? (int)$data['is_active'] : 1
                    ]
                );
                break;

            case 'image_generation':
            default:
                $name = trim($data['name']);
                if (empty($name)) throw new \Exception("نام مدل الزامی است");
                $cost = (int)($data['cost_per_image'] ?? 2);
                if ($cost < 1) throw new \Exception("هزینه باید حداقل ۱ باشد");
                $this->db->query(
                    "INSERT INTO ai_image_models (name, provider, cost_per_image, model_config, is_active) VALUES (?, ?, ?, ?, ?)",
                    [
                        $name,
                        $data['provider'] ?? 'gapgpt',
                        $cost,
                        ($data['model_config'] ?? '{}'),
                        isset($data['is_active']) ? (int)$data['is_active'] : 1
                    ]
                );
                break;
        }
    }

    /**
     * Update a model by table + id.
     */
    public function updateModel(int $id, array $data): void
    {
        $modelType = $data['model_type'] ?? 'image_generation';

        switch ($modelType) {
            case 'text':
                $this->db->query(
                    "UPDATE ai_text_models SET name = ?, provider = ?, cost_per_input_char = ?, cost_per_output_char = ?, free_model = ?, model_config = ?, is_active = ? WHERE id = ?",
                    [
                        trim($data['name']),
                        $data['provider'] ?? 'openrouter',
                        (float)($data['cost_per_input_char'] ?? 0.000001),
                        (float)($data['cost_per_output_char'] ?? 0.000002),
                        isset($data['free_model']) ? (int)$data['free_model'] : 0,
                        ($data['model_config'] ?? '{}'),
                        isset($data['is_active']) ? (int)$data['is_active'] : 1,
                        $id
                    ]
                );
                break;

            case 'image_editing':
                $this->db->query(
                    "UPDATE ai_edit_models SET name = ?, provider = ?, cost_per_edit = ?, model_config = ?, is_active = ? WHERE id = ?",
                    [
                        trim($data['name']),
                        $data['provider'] ?? 'gapgpt',
                        (int)($data['cost_per_image'] ?? 2),
                        ($data['model_config'] ?? '{}'),
                        isset($data['is_active']) ? (int)$data['is_active'] : 1,
                        $id
                    ]
                );
                break;

            case 'video':
                $this->db->query(
                    "UPDATE ai_video_models SET name = ?, provider = ?, cost_per_video = ?, model_config = ?, is_active = ? WHERE id = ?",
                    [
                        trim($data['name']),
                        $data['provider'] ?? 'gapgpt',
                        (int)($data['cost_per_image'] ?? 5),
                        ($data['model_config'] ?? '{}'),
                        isset($data['is_active']) ? (int)$data['is_active'] : 1,
                        $id
                    ]
                );
                break;

            case 'image_generation':
            default:
                $this->db->query(
                    "UPDATE ai_image_models SET name = ?, provider = ?, cost_per_image = ?, model_config = ?, is_active = ? WHERE id = ?",
                    [
                        trim($data['name']),
                        $data['provider'] ?? 'gapgpt',
                        (int)($data['cost_per_image'] ?? 2),
                        ($data['model_config'] ?? '{}'),
                        isset($data['is_active']) ? (int)$data['is_active'] : 1,
                        $id
                    ]
                );
                break;
        }
    }

    public function toggleModel(int $id): void
    {
        // Try each table
        $affected = $this->db->query("UPDATE ai_image_models SET is_active = 1 - is_active WHERE id = ?", [$id])->rowCount();
        if ($affected > 0) return;
        $affected = $this->db->query("UPDATE ai_edit_models SET is_active = 1 - is_active WHERE id = ?", [$id])->rowCount();
        if ($affected > 0) return;
        $affected = $this->db->query("UPDATE ai_text_models SET is_active = 1 - is_active WHERE id = ?", [$id])->rowCount();
        if ($affected > 0) return;
        $this->db->query("UPDATE ai_video_models SET is_active = 1 - is_active WHERE id = ?", [$id]);
    }

    public function deleteModel(int $id): void
    {
        $this->db->query("DELETE FROM ai_image_models WHERE id = ?", [$id]);
        $this->db->query("DELETE FROM ai_edit_models WHERE id = ?", [$id]);
        $this->db->query("DELETE FROM ai_text_models WHERE id = ?", [$id]);
        $this->db->query("DELETE FROM ai_video_models WHERE id = ?", [$id]);
    }
}