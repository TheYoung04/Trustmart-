<?php
/**
 * Verification for fixes L1 / L2 / L3.
 * Run: php scratch/test_fixes_l123.php
 * Seeds isolated rows and deletes them afterwards. L2 invokes the real
 * payment/expire_unpaid.php script; it reports any other old unpaid orders
 * it also expires (correct real-world behaviour, disclosed in output).
 */

$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/config/payment.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail) { echo ($c?"  [PASS] ":"  [FAIL] ").$n."\n"; $c ? $pass++ : $fail++; }

// Discover valid seed ids
$product = $pdo->query("SELECT product_id, seller_id, price, stock, sold_count FROM products WHERE status='Aktif' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$sellerId = (int)$product['seller_id'];
$buyer = $pdo->prepare("SELECT user_id FROM users WHERE role IN ('pembeli','penjual') AND user_id <> ? LIMIT 1");
$buyer->execute([$sellerId]);
$buyerId = (int)$buyer->fetchColumn();
echo "Seed refs: product={$product['product_id']} seller=$sellerId buyer=$buyerId price={$product['price']}\n\n";

function seedTxn(PDO $pdo, $buyerId, $sellerId, $productId, $amount) {
    $stmt = $pdo->prepare("INSERT INTO transactions
        (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, payment_type, payment_status, status)
        VALUES (?, ?, ?, 1, ?, 'E-Wallet','online','unpaid','Menunggu')");
    $stmt->execute([$buyerId, $sellerId, $productId, $amount]);
    return (int)$pdo->lastInsertId();
}

// ────────────────────────────────────────────────────────────
echo "== L1: buyer cancel restricted to Menunggu (static) ==\n";
$buyer = file_get_contents($root . '/dashboard/buyer.php');
check("cancel_order guard allows only ['Menunggu']",
    strpos($buyer, "in_array(\$txn['status'], ['Menunggu'], true)") !== false, $pass, $fail);
check("cancel_order no longer allows 'Disahkan'",
    strpos($buyer, "['Menunggu','Disahkan']") === false, $pass, $fail);
check("Disahkan cancel points user to dispute/contact",
    stripos($buyer, 'failkan aduan') !== false, $pass, $fail);

// ────────────────────────────────────────────────────────────
echo "\n== L3: process_payment_refund now completes (live) ==\n";
$txId = seedTxn($pdo, $buyerId, $sellerId, $product['product_id'], $product['price']);
$ref  = 'TMREF' . date('His') . random_int(100,999);
$pdo->prepare("INSERT INTO payments (transaction_id, payment_reference, payment_method, amount, currency, status)
               VALUES (?, ?, 'fpx', ?, 'MYR', 'completed')")->execute([$txId, $ref, $product['price']]);
$payId = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE transactions SET payment_status='paid' WHERE transaction_id=?")->execute([$txId]);

$res = process_payment_refund($pdo, $payId, 'L3 test', 'auto');
check("refund success (was broken by missing columns)", $res['success'] === true, $pass, $fail);
$pp = $pdo->query("SELECT status, refund_status FROM payments WHERE payment_id=$payId")->fetch(PDO::FETCH_ASSOC);
$tt = $pdo->query("SELECT payment_status FROM transactions WHERE transaction_id=$txId")->fetch(PDO::FETCH_ASSOC);
check("payment.status -> refunded (got {$pp['status']})", $pp['status'] === 'refunded', $pass, $fail);
check("payment.refund_status -> completed (got {$pp['refund_status']})", $pp['refund_status'] === 'completed', $pass, $fail);
check("transaction.payment_status -> refunded (got {$tt['payment_status']})", $tt['payment_status'] === 'refunded', $pass, $fail);

$pdo->prepare("DELETE FROM payments WHERE payment_id=?")->execute([$payId]);
$pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$txId]);

// ────────────────────────────────────────────────────────────
echo "\n== L2: auto-expiry of unpaid online order (real script) ==\n";
$amount = $product['price'];
// Simulate the buy: decrement stock, create old unpaid online order.
$pdo->prepare("UPDATE products SET stock = stock - 1, sold_count = sold_count + 1 WHERE product_id=?")->execute([$product['product_id']]);
$txId2 = seedTxn($pdo, $buyerId, $sellerId, $product['product_id'], $amount);
$pdo->prepare("UPDATE transactions SET created_at = (NOW() - INTERVAL 90 MINUTE) WHERE transaction_id=?")->execute([$txId2]);
$stockBefore = (int)$pdo->query("SELECT stock FROM products WHERE product_id={$product['product_id']}")->fetchColumn();

// Pre-existing other candidates (blast radius disclosure)
$others = $pdo->query("SELECT transaction_id FROM transactions WHERE payment_type='online'
        AND payment_status IN ('unpaid','pending') AND status='Menunggu'
        AND created_at < (NOW() - INTERVAL " . (int)PAYMENT_TIMEOUT_MINUTES . " MINUTE)
        AND transaction_id <> $txId2")->fetchAll(PDO::FETCH_COLUMN);
echo "  [INFO] other pre-existing expired-order " . count($others) . " transaction(s): [" . implode(',', $others) . "]\n";

// Run the real maintenance script
$script = $root . '/payment/expire_unpaid.php';
echo shell_exec("C:\\xampp\\php\\php.exe " . escapeshellarg($script) . " 2>&1");

$tx2 = $pdo->query("SELECT status, payment_status, cancelled_by FROM transactions WHERE transaction_id=$txId2")->fetch(PDO::FETCH_ASSOC);
$stockAfter = (int)$pdo->query("SELECT stock FROM products WHERE product_id={$product['product_id']}")->fetchColumn();
check("expired order -> Dibatalkan (got {$tx2['status']})", $tx2['status'] === 'Dibatalkan', $pass, $fail);
check("cancelled_by = system (got {$tx2['cancelled_by']})", $tx2['cancelled_by'] === 'system', $pass, $fail);
check("payment_status -> failed (got {$tx2['payment_status']})", $tx2['payment_status'] === 'failed', $pass, $fail);
check("stock restored (+1): before=$stockBefore after=$stockAfter", $stockAfter === $stockBefore + 1, $pass, $fail);

// Cleanup: revert the simulated stock move + remove seed rows
$pdo->prepare("UPDATE products SET stock = stock - 1, sold_count = GREATEST(sold_count - 1, 0) WHERE product_id=?")->execute([$product['product_id']]);
$pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$txId2]);

echo "\n==================================\nRESULT: $pass passed, $fail failed\n==================================\n";
exit($fail === 0 ? 0 : 1);
