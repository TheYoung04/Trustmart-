<?php
require_once __DIR__ . '/../config/db.php';

echo "=== USERS TABLE SCHEMA ===\n";
$stmt = $pdo->query('DESCRIBE users');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['Field']} ({$row['Type']})\n";
}

echo "\n=== PRODUCTS TABLE SCHEMA ===\n";
$stmt = $pdo->query('DESCRIBE products');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['Field']} ({$row['Type']})\n";
}

echo "\n=== TRANSACTIONS TABLE SCHEMA ===\n";
$stmt = $pdo->query('DESCRIBE transactions');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['Field']} ({$row['Type']})\n";
}
