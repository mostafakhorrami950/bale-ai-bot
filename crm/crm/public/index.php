<?php
/**
 * CRM Travel Agency - Main Entry Point
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'Core\\';
    $baseDir = __DIR__ . '/../core/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Load environment variables from .env
function loadEnv(): void {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }
}

loadEnv();

// Load config
$config = require __DIR__ . '/../config/app.php';
$GLOBALS['app_config'] = $config;

// Set timezone
date_default_timezone_set($config['timezone']);

// Start session
\Core\Session::start();

// Check if installation is needed
$db = $config['db'];
$needsInstall = true;
try {
    $pdo = new PDO(
        "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Check if tables exist
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) {
        $needsInstall = false;
    }
} catch (\PDOException $e) {
    // Database doesn't exist yet, needs install
}

// Handle installation
$url = $_GET['url'] ?? '';
if ($needsInstall && strpos($url, 'install') === false && strpos($url, 'setup') === false) {
    header('Location: install.php');
    exit;
}

// Load routes
require __DIR__ . '/../routes/web.php';

// Dispatch the request
\Core\Router::dispatch();