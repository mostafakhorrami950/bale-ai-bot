<?php
require __DIR__ . '/init.php';

$db = Database\Database::getInstance();
$conn = $db->getConnection();
echo "Users table columns:\n";
$stmt = $conn->query("DESCRIBE users");
foreach ($stmt as $row) {
    echo $row['Field'] . ' (' . $row['Type'] . ')' . "\n";
}
echo "\nFound user count: ";
$stmt = $conn->query("SELECT COUNT(*) as c FROM users");
$row = $stmt->fetch();
echo $row['c'] . "\n";