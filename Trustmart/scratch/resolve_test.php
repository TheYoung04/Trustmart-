<?php
// scratch/resolve_test.php
require_once __DIR__ . '/../config/db.php';

try {
    $dispute_id = 4;
    echo "Attempting to resolve dispute ID $dispute_id...\n";

    $stmt = $pdo->prepare("
        UPDATE disputes
           SET status = 'Selesai',
               resolution = 'Memihak Pembeli',
               resolved_by = 3,
               resolved_at = CURRENT_TIMESTAMP
         WHERE dispute_id = ?
    ");
    $stmt->execute([$dispute_id]);
    echo "Dispute resolved successfully.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
