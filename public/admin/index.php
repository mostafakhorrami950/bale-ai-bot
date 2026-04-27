<?php
/**
 * Admin panel index — redirects to dashboard.
 */
require_once __DIR__ . '/../../init.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
header('Location: dashboard.php');
exit;