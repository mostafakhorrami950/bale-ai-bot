<?php

namespace Modules\Admin;

use Database\Database;
use Core\AILogger;

class ModelManager
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Map model types to their database tables.
     */
    public static function typeToTable(string $type): ?string
    {
        $map = [
            'image_generation' => 'ai_image_models',
            'image_editing'    => 'ai_edit_models',
            'text'             => 'ai_text_models',
            'video'            => 'ai_video_models',
        ];
        return $map[$type] ?? null;
    }

    public static function tableToType(?string $table): string
    {
        $map = [
            'ai_image_models' => 'image_generation',
            'ai_edit_models'  => 'image_editing',
            'ai_text_models'  => 'text',
            'ai_video_models' => 'video',
        ];
        return $map[$table] ?? 'image_generation';
    }

    /**
     * Get all models from all tables, unified.
     */
    public function getAllModels(): array
    {
        $all = [];

        $imageRows = $this->db->query(
            "SELECT id, name, display_name, description, provider, cost_per_image AS cost, size, aspect_ratio, model_config, is_active, created_at, 'image_generation' AS model_type FROM ai_image_models ORDER BY created_at DESC"
        )->fetchAll();
        foreach ($imageRows as $r) { $r['cost_label'] = $r['cost'] . ' اعتبار'; $all[] = $r; }

        $editRows = $this->db->query(
            "SELECT id, name, display_name, description, provider, cost_per_edit AS cost, size, aspect_ratio, model_config, is_active, created_at, 'image_editing' AS model_type FROM ai_edit_models ORDER BY created_at DESC"
        )->fetchAll();
        foreach ($editRows as $r) { $r['cost_label'] = $r['cost'] . ' اعتبار'; $all[] = $r; }

        $textRows = $this->db->query(
            "SELECT id, name, display_name, description, provider, cost_per_input_char AS cost, cost_per_output_char, free_model, model_config, is_active, created_at, 'text' AS model_type FROM ai_text_models ORDER BY created_at DESC"
        )->fetchAll();
        foreach ($textRows as $r) {
            $r['cost_label'] = $r['free_model'] ? '🆓 رایگان' : ($r['cost'] . '/' . ($r['cost_per_output_char'] ?? 0));
            $all[] = $r;
        }

        $videoRows = $this->db->query(
            "SELECT id, name, display_name, description, provider, cost_per_video AS cost, model_config, is_active, created_at, 'video' AS model_type FROM ai_video_models ORDER BY created_at DESC"
        )->fetchAll();
        foreach ($videoRows as $r) { $r['cost_label'] = $r['cost'] . ' اعتبار'; $all[] = $r; }

        usort($all, function ($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        return $all;
    }

    /**
     * Get a model by ID from a specific type table.
     */
    public function getById(int $id, string $modelType): ?array
    {
        $table = self::typeToTable($modelType);
        if (!$table) return null;

        $stmt = $this->db->query("SELECT * FROM `{$table}` WHERE id = ?", [$id]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $row['model_type'] = $modelType;
        return $this->normalizeRow($row, $modelType);
    }

    private function normalizeRow(array $row, string $type): array
    {
        if ($type === 'image_editing') {
            $row['cost_per_image'] = $row['cost_per_edit'] ?? 0;
        } elseif ($type === 'video') {
            $row['cost_per_image'] = $row['cost_per_video'] ?? 0;
        } else {
            // cost_per_image already there for image_generation
            $row['cost_per_image'] = $row['cost_per_image'] ?? 0;
        }
        $row['model_type'] = $type;
        return $row;
    }

    /**
     * Create a model. $data must contain model_type.
     */
    public function createModel(array $data): void
    {
        $modelType = $data['model_type'] ?? 'image_generation';
        $name = trim($data['name'] ?? '');
        if ($name === '') throw new \Exception('نام مدل الزامی است');

        $displayName = trim($data['display_name'] ?? $name);
        $description = trim($data['description'] ?? '');
        $config = $data['model_config'] ?? '{}';
        $active = isset($data['is_active']) ? (int)$data['is_active'] : 1;

        AILogger::log('MODEL_CREATE', ['name' => $name, 'type' => $modelType, 'display_name' => $displayName]);

        switch ($modelType) {
            case 'text':
                $supportedFormats = $data['supported_formats'] ?? 'txt,doc,pdf,jpg,jpeg,png,gif,webp';
                $sortOrder = (int)($data['sort_order'] ?? 0);
                $this->db->query(
                    "INSERT INTO ai_text_models (name, display_name, description, provider, cost_per_input_char, cost_per_output_char, free_model, supported_formats, sort_order, model_config, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $name, $displayName, $description,
                        $data['provider'] ?? 'openrouter',
                        (float)($data['cost_per_input_char'] ?? 0.000001),
                        (float)($data['cost_per_output_char'] ?? 0.000002),
                        $data['free_model'] ?? 0,
                        $supportedFormats,
                        $sortOrder,
                        $config, $active,
                    ]
                );
                break;

            case 'image_editing':
                $cost = (int)($data['cost_per_image'] ?? 2);
                if ($cost < 1) throw new \Exception('هزینه باید حداقل ۱ باشد');
                $size = $data['size'] ?? 'auto';
                $ar = $data['aspect_ratio'] ?? 'auto';
                $this->db->query(
                    "INSERT INTO ai_edit_models (name, display_name, description, provider, cost_per_edit, size, aspect_ratio, model_config, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$name, $displayName, $description, $data['provider'] ?? 'openrouter', $cost, $size, $ar, $config, $active]
                );
                break;

            case 'video':
                $cost = (int)($data['cost_per_image'] ?? 5);
                if ($cost < 1) throw new \Exception('هزینه باید حداقل ۱ باشد');
                $this->db->query(
                    "INSERT INTO ai_video_models (name, display_name, description, provider, cost_per_video, model_config, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$name, $displayName, $description, $data['provider'] ?? 'openrouter', $cost, $config, $active]
                );
                break;

            case 'image_generation':
            default:
                $cost = (int)($data['cost_per_image'] ?? 2);
                if ($cost < 1) throw new \Exception('هزینه باید حداقل ۱ باشد');
                $size = $data['size'] ?? 'auto';
                $ar = $data['aspect_ratio'] ?? 'auto';
                $this->db->query(
                    "INSERT INTO ai_image_models (name, display_name, description, provider, cost_per_image, size, aspect_ratio, model_config, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$name, $displayName, $description, $data['provider'] ?? 'openrouter', $cost, $size, $ar, $config, $active]
                );
                break;
        }

        AILogger::log('MODEL_CREATED', ['name' => $name, 'type' => $modelType, 'display_name' => $displayName]);
    }

    /**
     * Update a model. $data must contain model_type.
     */
    public function updateModel(int $id, array $data): void
    {
        $newType = $data['model_type'] ?? 'image_generation';
        $table = self::typeToTable($newType);
        if (!$table) throw new \Exception('نوع مدل نامعتبر');

        $name = trim($data['name'] ?? '');
        if ($name === '') throw new \Exception('نام مدل الزامی است');

        $displayName = trim($data['display_name'] ?? $name);
        $description = trim($data['description'] ?? '');
        $config = $data['model_config'] ?? '{}';
        $active = isset($data['is_active']) ? (int)$data['is_active'] : 1;

        AILogger::log('MODEL_UPDATE', ['id' => $id, 'type' => $newType, 'table' => $table]);

        switch ($newType) {
            case 'text':
                $supportedFormats = $data['supported_formats'] ?? 'txt,doc,pdf,jpg,jpeg,png,gif,webp';
                $sortOrder = (int)($data['sort_order'] ?? 0);
                $this->db->query(
                    "UPDATE ai_text_models SET name=?, display_name=?, description=?, provider=?, cost_per_input_char=?, cost_per_output_char=?, free_model=?, supported_formats=?, sort_order=?, model_config=?, is_active=? WHERE id=?",
                    [
                        $name, $displayName, $description,
                        $data['provider'] ?? 'openrouter',
                        (float)($data['cost_per_input_char'] ?? 0.000001),
                        (float)($data['cost_per_output_char'] ?? 0.000002),
                        $data['free_model'] ?? 0,
                        $supportedFormats,
                        $sortOrder,
                        $config, $active, $id,
                    ]
                );
                break;

            case 'image_editing':
                $cost = (int)($data['cost_per_image'] ?? 2);
                if ($cost < 1) throw new \Exception('هزینه باید حداقل ۱ باشد');
                $size = $data['size'] ?? 'auto';
                $ar = $data['aspect_ratio'] ?? 'auto';
                $this->db->query(
                    "UPDATE ai_edit_models SET name=?, display_name=?, description=?, provider=?, cost_per_edit=?, size=?, aspect_ratio=?, model_config=?, is_active=? WHERE id=?",
                    [$name, $displayName, $description, $data['provider'] ?? 'openrouter', $cost, $size, $ar, $config, $active, $id]
                );
                break;

            case 'video':
                $cost = (int)($data['cost_per_image'] ?? 5);
                if ($cost < 1) throw new \Exception('هزینه باید حداقل ۱ باشد');
                $this->db->query(
                    "UPDATE ai_video_models SET name=?, display_name=?, description=?, provider=?, cost_per_video=?, model_config=?, is_active=? WHERE id=?",
                    [$name, $displayName, $description, $data['provider'] ?? 'openrouter', $cost, $config, $active, $id]
                );
                break;

            case 'image_generation':
            default:
                $cost = (int)($data['cost_per_image'] ?? 2);
                if ($cost < 1) throw new \Exception('هزینه باید حداقل ۱ باشد');
                $size = $data['size'] ?? 'auto';
                $ar = $data['aspect_ratio'] ?? 'auto';
                $this->db->query(
                    "UPDATE ai_image_models SET name=?, display_name=?, description=?, provider=?, cost_per_image=?, size=?, aspect_ratio=?, model_config=?, is_active=? WHERE id=?",
                    [$name, $displayName, $description, $data['provider'] ?? 'openrouter', $cost, $size, $ar, $config, $active, $id]
                );
                break;
        }

        AILogger::log('MODEL_UPDATED', ['id' => $id, 'type' => $newType, 'display_name' => $displayName, 'cost' => $data['cost_per_image'] ?? null]);
    }

    public function toggleModel(int $id, string $modelType): void
    {
        $table = self::typeToTable($modelType);
        if ($table) {
            $this->db->query("UPDATE `{$table}` SET is_active = 1 - is_active WHERE id = ?", [$id]);
        }
    }

    public function deleteModel(int $id, string $modelType): void
    {
        $table = self::typeToTable($modelType);
        if ($table) {
            $this->db->query("DELETE FROM `{$table}` WHERE id = ?", [$id]);
        }
    }
}