<?php
$f = __DIR__ . '/debug_ping.txt';
file_put_contents($f, date('Y-m-d H:i:s') . " PING OK\n", FILE_APPEND);
@file_put_contents(__DIR__ . '/debug.txt', date('[Y-m-d H:i:s]') . " PING_FROM_TEST\n", FILE_APPEND);
echo "PING OK - " . date('Y-m-d H:i:s');