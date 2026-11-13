<?php
require_once __DIR__ . '/../config/db.php';

try {
    $hash = password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = 'admin@uthm.edu.my'");
    $stmt->execute([$hash]);
    $affected = $stmt->rowCount();
    if ($affected) {
        echo "Successfully reset admin password to 'password123'.\n";
    } else {
        echo "Warning: No admin account found with email admin@uthm.edu.my\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
