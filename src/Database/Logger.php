<?php

namespace Database;

class Logger
{
    public static function log(string $level, string $message, array $context = [])
    {
        try {
            $db = Database::getInstance();
            $sql = "INSERT INTO bot_logs (level, message, context) VALUES (?, ?, ?)";
            $db->query($sql, [
                $level,
                $message,
                json_encode($context, JSON_UNESCAPED_UNICODE)
            ]);
        } catch (\Exception $e) {
            // Fallback to file logging if database fails
            error_log("Bot Logger Failure: " . $e->getMessage());
            error_log("Original Log [$level]: $message " . json_encode($context));
        }
    }

    public static function info(string $message, array $context = [])
    {
        self::log('INFO', $message, $context);
    }

    public static function error(string $message, array $context = [])
    {
        self::log('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = [])
    {
        self::log('WARNING', $message, $context);
    }

    public static function debug(string $message, array $context = [])
    {
        if (\Core\Config::get('APP_DEBUG') === 'true') {
            self::log('DEBUG', $message, $context);
        }
    }
}