<?php
require_once __DIR__ . '/../config/db.php';

echo "=== TRANSACTIONS ===\n";
foreach ($pdo->query('SELECT transaction_id, buyer_id, seller_id, product_id, status, total_amount FROM transactions ORDER BY transaction_id')->fetchAll() as $t) {
    echo "ID:{$t['transaction_id']} buyer:{$t['buyer_id']} seller:{$t['seller_id']} product:{$t['product_id']} status:{$t['status']} RM{$t['total_amount']}\n";
}

echo "\n=== REVIEWS ===\n";
foreach ($pdo->query('SELECT review_id, seller_id, buyer_id, rating FROM reviews')->fetchAll() as $r) {
    echo "ID:{$r['review_id']} seller:{$r['seller_id']} buyer:{$r['buyer_id']} rating:{$r['rating']}\n";
}

echo "\n=== DISPUTES ===\n";
foreach ($pdo->query('SELECT dispute_id, transaction_id, status, resolution FROM disputes')->fetchAll() as $d) {
    echo "ID:{$d['dispute_id']} txn:{$d['transaction_id']} status:{$d['status']} resolution:{$d['resolution']}\n";
}
