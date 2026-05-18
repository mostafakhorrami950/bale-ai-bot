<?php

define('BASE_PATH', __DIR__);

// Basic Autoloader
spl_autoload_register(function ($class) {
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Load Config
Core\Config::load(BASE_PATH . '/.env');

// Start Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
