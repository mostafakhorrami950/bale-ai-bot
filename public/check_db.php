<?php
require_once __DIR__ . '/../init.php';

use Database\Database;

$db = Database::getInstance();
$conn = $db->getConnection();

header('Content-Type: text/plain; charset=utf-8');

echo "=== Users table columns ===\n";
$stmt = $conn->query("DESCRIBE users");
foreach ($stmt as $row) {
    echo $row['Field'] . ' (' . $row['Type'] . ')';
    if ($row['Key']) echo ' KEY:' . $row['Key'];
    echo "\n";
}

echo "\n=== Sample user ===\n";
$stmt = $conn->query("SELECT * FROM users LIMIT 1");
$row = $stmt->fetch();
if ($row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo "No users found\n";
}