<?php
namespace Core;

class ActivityLog
{
    public static function log(string $action, string $entityType = null, int $entityId = null, string $description = null): void
    {
        $config = $GLOBALS['app_config'];
        
        // Check if activity log feature is enabled
        if (isset($config['features']['activity_log']) && !$config['features']['activity_log']) {
            return;
        }

        $db = Database::getInstance();
        $db->insert('activity_logs', [
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    public static function getRecent(int $limit = 20): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT al.*, u.full_name as user_name 
             FROM activity_logs al 
             LEFT JOIN users u ON al.user_id = u.id 
             ORDER BY al.created_at DESC 
             LIMIT :limit",
            [':limit' => $limit]
        );
    }

    public static function getByEntity(string $entityType, int $entityId): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT al.*, u.full_name as user_name 
             FROM activity_logs al 
             LEFT JOIN users u ON al.user_id = u.id 
             WHERE al.entity_type = :type AND al.entity_id = :id 
             ORDER BY al.created_at DESC",
            [':type' => $entityType, ':id' => $entityId]
        );
    }
}