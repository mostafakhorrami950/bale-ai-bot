<?php
require_once __DIR__ . '/init.php';
$_SESSION = [];
session_destroy();
header('Location: /web/login.php');
exit;