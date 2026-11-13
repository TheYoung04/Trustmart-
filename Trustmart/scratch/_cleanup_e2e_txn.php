<?php
/**
 * One-off cleanup helper for test_ai_hold_e2e.php leftovers.
 * (The e2e test now cleans up itself; this exists in case it is interrupted.)
 */
require_once dirname(__DIR__) . '/config/db.php';
$txn = $pdo->query(
    "SELECT t.transaction_id FROM transactions t
       JOIN users u ON u.user_id = t.buyer_id
      WHERE u.email='aishah@student.uthm.edu.my' AND t.product_id=1
        AND t.status='Menunggu' AND t.total_amount=(SELECT price FROM products WHERE product_id=1)
        AND t.created_at >= NOW() - INTERVAL 15 MINUTE
      ORDER BY t.transaction_id DESC LIMIT 1"
)->fetchColumn();
if ($txn) {
    $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$txn]);
    $pdo->prepare("UPDATE products SET stock=stock+1, sold_count=GREATEST(sold_count-1,0) WHERE product_id=1")->execute();
    echo "Removed test txn $txn and restored stock\n";
} else {
    echo "No leftover test txn found\n";
}
