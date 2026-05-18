<?php
/**
 * Custom error & exception handler — writes everything to debug.txt
 * ALWAYS returns HTTP 200 to prevent Bale from retrying on logic errors.
 */

// Ensure debug.txt exists and is writable — create if missing
$debugFile = __DIR__ . '/debug.txt';
if (!file_exists($debugFile)) {
    @touch($debugFile);
    @chmod($debugFile, 0666);
}

// Redirect all PHP errors
@ini_set('display_errors', 0);
@ini_set('log_errors', 1);
@ini_set('error_log', $debugFile);

// Catch all uncaught exceptions
set_exception_handler(function (Throwable $e) {
    $msg = date('[Y-m-d H:i:s]') . " FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    @file_put_contents(__DIR__ . '/debug.txt', $msg, FILE_APPEND);
    if (!headers_sent()) {
        http_response_code(200);
    }
    exit;
});

// Catch all PHP errors
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $msg = date('[Y-m-d H:i:s]') . " ERROR [$errno]: $errstr in $errfile:$errline\n";
    @file_put_contents(__DIR__ . '/debug.txt', $msg, FILE_APPEND);
});