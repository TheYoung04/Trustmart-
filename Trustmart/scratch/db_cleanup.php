<?php
require_once __DIR__ . '/../config/db.php';

try {
    $stmt = $pdo->prepare("
        UPDATE products 
        SET status = 'Dipadam' 
        WHERE seller_id IN (
            SELECT user_id FROM users 
            WHERE role = 'penjual' AND verification_status != 'Disahkan'
        )
    ");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "Successfully updated {$count} products from unverified sellers to 'Dipadam'.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
