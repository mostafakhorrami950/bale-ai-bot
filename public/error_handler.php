<?php
/**
 * Custom error & exception handler — writes everything to debug.txt
 */

// Redirect all PHP errors to debug.txt
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.txt');

// Catch all uncaught exceptions
set_exception_handler(function (Throwable $e) {
    $msg = date('[Y-m-d H:i:s]') . " FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    file_put_contents(__DIR__ . '/debug.txt', $msg, FILE_APPEND);
});

// Catch all PHP errors
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $msg = date('[Y-m-d H:i:s]') . " ERROR [$errno]: $errstr in $errfile:$errline\n";
    file_put_contents(__DIR__ . '/debug.txt', $msg, FILE_APPEND);
});