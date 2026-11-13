<?php
/**
 * End-to-end HTTP test of payment/callback.php against the live web server.
 * Proves Fix 2 (callback signature path uses defined $payment_check, no undefined-var fatal)
 * and that callback auth + idempotent completion works.
 *
 * Creates an isolated transaction + payment, then DELETES them on cleanup.
 * Run: php scratch/test_e2e_callback.php
 */

$root = dirname(__DIR__);
require_once $root . '/config/db.php';   // provides $pdo
require_once $root . '/config/payment.php';

$base = PAYMENT_BASE_URL;               // http://localhost/Trustmart
echo "Base URL: $base\n";

function post($url, $fields) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, trim((string)$body)];
}

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail) { echo ($c?"  [PASS] ":"  [FAIL] ").$n."\n"; $c ? $pass++ : $fail++; }

// ── Discover valid ids ───────────────────────────────────────
$roleCol = null;
foreach ($pdo->query("SHOW COLUMNS FROM users") as $c) {
    if (in_array($c['Field'], ['role','user_type','type'])) { $roleCol = $c['Field']; break; }
}
$product = $pdo->query("SELECT product_id, seller_id, price FROM products WHERE status='Aktif' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$sellerId = (int)$product['seller_id'];
if ($roleCol) {
    $buyer = $pdo->prepare("SELECT user_id FROM users WHERE `$roleCol` IN ('pembeli','buyer') AND user_id <> ? LIMIT 1");
    $buyer->execute([$sellerId]);
} else {
    $buyer = $pdo->prepare("SELECT user_id FROM users WHERE user_id <> ? LIMIT 1");
    $buyer->execute([$sellerId]);
}
$buyerId = (int)$buyer->fetchColumn();
$amount  = (float)$product['price'];
echo "Using product={$product['product_id']} seller=$sellerId buyer=$buyerId amount=$amount\n";

// ── Seed isolated records ────────────────────────────────────
$pdo->beginTransaction();
$stmt = $pdo->prepare("INSERT INTO transactions
    (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, payment_type, payment_status, status)
    VALUES (?, ?, ?, 1, ?, 'E-Wallet', 'online', 'unpaid', 'Menunggu')");
$stmt->execute([$buyerId, $sellerId, $product['product_id'], $amount]);
$txnId = (int)$pdo->lastInsertId();

$ref = 'TST' . date('YmdHis') . substr(md5(uniqid('', true)), 0, 6);
$stmt = $pdo->prepare("INSERT INTO payments (transaction_id, payment_reference, payment_method, amount, currency, status)
    VALUES (?, ?, 'fpx', ?, 'MYR', 'pending')");
$stmt->execute([$txnId, $ref, $amount]);
$payId = (int)$pdo->lastInsertId();
$pdo->commit();
echo "Seeded txn=$txnId payment=$payId ref=$ref\n";

$cbUrl = "$base/payment/callback.php";

try {
    // ── Test A: unsigned callback must be rejected (401) ──────
    [$codeA, $bodyA] = post($cbUrl, ['refno' => $ref, 'status_id' => '1', 'amount' => number_format($amount,2,'.','')]);
    check("Unsigned callback rejected -> HTTP 401 (got $codeA: '$bodyA')", $codeA === 401, $pass, $fail);

    // ── Test B: forged/invalid signature must be rejected (403) ─
    [$codeB, $bodyB] = post($cbUrl, ['refno' => $ref, 'status_id' => '1', 'amount' => number_format($amount,2,'.',''), 'signature' => 'deadbeef']);
    check("Bad-signature callback rejected -> HTTP 403 (got $codeB: '$bodyB')", $codeB === 403, $pass, $fail);

    // ── Test C: valid HMAC signature accepted -> payment completes ─
    $sig = generate_payment_signature($ref, '1', $amount);
    [$codeC, $bodyC] = post($cbUrl, ['refno' => $ref, 'status_id' => '1', 'amount' => number_format($amount,2,'.',''), 'signature' => $sig]);
    check("Signed callback accepted -> HTTP 200 (got $codeC: '$bodyC')", $codeC === 200, $pass, $fail);

    // Verify DB state
    $p = $pdo->query("SELECT status FROM payments WHERE payment_id=$payId")->fetch(PDO::FETCH_ASSOC);
    $t = $pdo->query("SELECT payment_status FROM transactions WHERE transaction_id=$txnId")->fetch(PDO::FETCH_ASSOC);
    check("payment.status -> completed (got {$p['status']})", $p['status'] === 'completed', $pass, $fail);
    check("transaction.payment_status -> paid (got {$t['payment_status']})", $t['payment_status'] === 'paid', $pass, $fail);

    // ── Test D: replay (idempotency) - second valid callback must not double-apply ─
    [$codeD, $bodyD] = post($cbUrl, ['refno' => $ref, 'status_id' => '1', 'amount' => number_format($amount,2,'.',''), 'signature' => $sig]);
    check("Replayed callback -> 'already processed' (got $codeD: '$bodyD')", stripos($bodyD, 'already') !== false, $pass, $fail);

} finally {
    // ── Cleanup: remove isolated seed data ───────────────────
    $pdo->prepare("DELETE FROM payments WHERE payment_id=?")->execute([$payId]);
    $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$txnId]);
    echo "Cleanup done (deleted payment=$payId txn=$txnId)\n";
}

echo "\n==================================\nRESULT: $pass passed, $fail failed\n==================================\n";
exit($fail === 0 ? 0 : 1);
