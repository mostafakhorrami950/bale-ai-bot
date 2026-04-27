<?php

namespace Modules\Admin;

use Database\Database;

class ApiManager
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getAllKeys()
    {
        return $this->db->query("SELECT * FROM api_keys ORDER BY created_at DESC")->fetchAll();
    }

    public function getActiveKey()
    {
        $result = $this->db->query("SELECT api_key FROM api_keys WHERE is_active = 1 AND provider = 'gapgpt' LIMIT 1")->fetch();
        return $result ? $result['api_key'] : null;
    }

    public function addKey(string $key, string $provider = 'gapgpt')
    {
        if (empty($key)) {
            throw new \Exception("کلید API نمی‌تواند خالی باشد.");
        }

        $sql = "INSERT INTO api_keys (provider, api_key, is_active) VALUES (?, ?, 0)";
        return $this->db->query($sql, [$provider, $key]);
    }

    public function deactivateAll(string $provider = 'gapgpt')
    {
        return $this->db->query("UPDATE api_keys SET is_active = 0 WHERE provider = ?", [$provider]);
    }

    public function setActive(int $id)
    {
        // Get the provider for this key first
        $stmt = $this->db->query("SELECT provider FROM api_keys WHERE id = ?", [$id]);
        $row = $stmt->fetch();
        $provider = $row ? $row['provider'] : 'gapgpt';
        
        $this->deactivateAll($provider);
        $sql = "UPDATE api_keys SET is_active = 1 WHERE id = ?";
        return $this->db->query($sql, [$id]);
    }

    public function deleteKey($id)
    {
        return $this->db->query("DELETE FROM api_keys WHERE id = ?", [(int)$id]);
    }
}