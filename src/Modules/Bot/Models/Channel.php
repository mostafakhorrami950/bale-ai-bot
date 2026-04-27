<?php

namespace Modules\Bot\Models;

use Database\Database;

class Channel
{
    public static function getAllRequired()
    {
        $db = Database::getInstance();
        return $db->query("SELECT * FROM required_channels WHERE is_active = 1")->fetchAll();
    }
}