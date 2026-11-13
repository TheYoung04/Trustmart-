<?php
require_once __DIR__ . '/../config/db.php';

try {
    $hash = password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE role IN ('penjual', 'pembeli')");
    $stmt->execute([$hash]);
    echo "Successfully updated passwords for all sellers and buyers to 'password123'.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
