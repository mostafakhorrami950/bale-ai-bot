<?php

namespace Modules\Admin;

use Database\Database;

class SettingsManager
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function get($key, $default = null)
    {
        $result = $this->db->query("SELECT value FROM settings WHERE key_name = ?", [$key])->fetch();
        return $result ? $result['value'] : $default;
    }

    public function set($key, $value)
    {
        $sql = "INSERT INTO settings (key_name, value) VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE value = ?, updated_at = CURRENT_TIMESTAMP";
        return $this->db->query($sql, [$key, $value, $value]);
    }

    public function getAll()
    {
        $results = $this->db->query("SELECT key_name, value FROM settings")->fetchAll();
        $settings = [];
        foreach ($results as $row) {
            $settings[$row['key_name']] = $row['value'];
        }
        return $settings;
    }
}