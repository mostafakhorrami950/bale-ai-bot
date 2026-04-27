<?php

namespace Core;

use Database\Logger;

class Config
{
    private static $config = [];

    /**
     * Load .env file into environment and static cache.
     * I1: Uses putenv() and reads back with getenv() instead of $_ENV.
     */
    public static function load($path)
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            putenv(sprintf('%s=%s', $name, $value));
            self::$config[$name] = $value;
        }
    }

    /**
     * Get a config value.
     * M4: Proper fallback chain: static cache → getenv → default.
     */
    public static function get($key, $default = null)
    {
        return self::$config[$key] ?? getenv($key) ?: $default;
    }
}