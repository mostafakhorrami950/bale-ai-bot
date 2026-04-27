<?php

namespace Modules\Bot\Models;

use Database\Database;

class AIModel
{
    /**
     * Get all active AI models.
     */
    public static function getAllActive(): array
    {
        $db = Database::getInstance();
        return $db->query("SELECT * FROM ai_models WHERE is_active = 1")->fetchAll();
    }

    /**
     * Find an active model by its id.
     */
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM ai_models WHERE id = ? AND is_active = 1", [$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Find an active model by its name.
     */
    public static function findByName(string $name): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM ai_models WHERE name = ? AND is_active = 1", [$name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}