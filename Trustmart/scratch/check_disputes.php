<?php
require_once __DIR__ . '/../config/db.php';
echo "=== TRANSACTIONS ===\n";
$txns = $pdo->query("SELECT transaction_id, buyer_id, seller_id, status FROM transactions")->fetchAll();
print_r($txns);

echo "\n=== DISPUTES ===\n";
$disputes = $pdo->query("SELECT dispute_id, transaction_id, filed_by, status, resolution FROM disputes")->fetchAll();
print_r($disputes);
