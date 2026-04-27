<?php

namespace Modules\Bot;

class UpdateFactory
{
    public static function create(array $data): Update
    {
        // Ensure basic structure exists for production hardening
        $data['message'] = $data['message'] ?? [];
        $data['callback_query'] = $data['callback_query'] ?? [];
        
        return new Update($data);
    }
}