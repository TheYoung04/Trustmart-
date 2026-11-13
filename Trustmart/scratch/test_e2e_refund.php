<?php
/**
 * scratch/test_e2e_refund.php  — LIVE E2E for Fix #6 (failed/missing refund
 * must NOT be recorded as refunded). Drives the real buyer.php?action=cancel_order
 * endpoint over HTTP against the running Apache+MySQL, exercising all four
 * refund branches. Fully self-cleans (snapshot + restore product stock, delete
 * crafted rows). Uses password123 for the demo buyer.
 */
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail, $d = '') {
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}
$jar = sys_get_temp_dir() . '/tm_refund.txt'; @unlink($jar);
function http($url, $post = null, $hdr = []) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar, CURLOPT_TIMEOUT=>20]);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$body];
}
function token_from($b) {
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $b, $m)) return $m[1];
    if (preg_match('/name="_csrf_token" value="([^"]+)"/', $b, $m)) return $m[1];
    return '';
}

echo "Target: $base\n";

// ── Buyer + product for the crafted orders; snapshot product for restore ──
$buyerEmail = $pdo->query("SELECT email FROM users WHERE role='pembeli' AND is_active=1 LIMIT 1")->fetchColumn();
$buyerId = (int) $pdo->query("SELECT user_id FROM users WHERE email=" . $pdo->quote($buyerEmail))->fetchColumn();
$prod = $pdo->query("SELECT product_id, seller_id, price FROM products WHERE product_id NOT IN (SELECT product_id FROM transactions WHERE buyer_id=$buyerId) ORDER BY product_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC)
      ?? $pdo->query("SELECT product_id, seller_id, price FROM products ORDER BY product_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$productId = (int)$prod['product_id']; $sellerId = (int)$prod['seller_id'];
$snap = $pdo->query("SELECT stock, sold_count FROM products WHERE product_id=$productId")->fetch(PDO::FETCH_ASSOC);
echo "buyer=$buyerEmail(id=$buyerId) product=$productId seller=$sellerId\n";

// ── Login ──
[, $page] = http("$base/public/"); $tok = token_from($page);
if ($tok === '') { check("CSRF token from landing", false, $pass, $fail); echo "ABORT\n"; exit(1); }
http("$base/auth/login.php", ['email'=>$buyerEmail,'password'=>'password123','_csrf_token'=>$tok],
     ['X-CSRF-Token: '.$tok, 'Accept: application/json']);
[, $dash] = http("$base/dashboard/buyer.php"); $tok = token_from($dash) ?: $tok;
echo "logged in (dashboard bytes=" . strlen($dash) . ")\n";

$made = [];
function cancel($tok, $txnId) {
    global $base;
    return http("$base/dashboard/buyer.php?action=cancel_order",
        ['transaction_id'=>$txnId, '_csrf_token'=>$tok], ['X-CSRF-Token: '.$tok]);
}
// craft a Menunggu + online + paid order (returns txn_id)
$craft = $pdo->prepare(
    "INSERT INTO transactions (buyer_id, seller_id, product_id, quantity, total_amount,
             payment_method, status, payment_type, payment_status, payment_reference, created_at)
     VALUES (?,?,?,?,1,'E-Wallet','Menunggu','online','paid',?, NOW())");

try {
    // ===== Scenario A: genuine completed payment -> must refund (no regression) =====
    $refA = 'E2EREFUND_A_' . bin2hex(random_bytes(4));
    $craft->execute([$buyerId, $sellerId, $productId, 1, $refA]);
    $txnA = (int)$pdo->lastInsertId(); $made[] = $txnA;
    $pdo->prepare("INSERT INTO payments (transaction_id,payment_reference,payment_method,amount,status,refund_status,paid_at)
        VALUES (?,?,'fpx',100.00,'completed','none',NOW())")->execute([$txnA, $refA]);
    [$c,$b] = cancel($tok, $txnA); $j = json_decode($b, true);
    $t = $pdo->query("SELECT status, payment_status FROM transactions WHERE transaction_id=$txnA")->fetch(PDO::FETCH_ASSOC);
    $p = $pdo->query("SELECT status, refund_status FROM payments WHERE payment_reference='$refA'")->fetch(PDO::FETCH_ASSOC);
    echo "\n== A: completed payment (real refund) ==\n";
    check("cancel_order accepted (200 success)", $c===200 && ($j['success']??false), $pass, $fail, substr($b,0,80));
    check("order -> Dibatalkan", $t['status']==='Dibatalkan', $pass, $fail);
    check("A genuine refund STILL marks 'refunded'", $t['payment_status']==='refunded', $pass, $fail, "got: {$t['payment_status']}");
    check("payment.status -> refunded, refund_status -> completed", $p['status']==='refunded' && $p['refund_status']==='completed', $pass, $fail, json_encode($p));

    // ===== Scenario B: payment still 'pending' (unverifiable) -> keep paid, flag failed =====
    $refB = 'E2EREFUND_B_' . bin2hex(random_bytes(4));
    $craft->execute([$buyerId, $sellerId, $productId, 1, $refB]);
    $txnB = (int)$pdo->lastInsertId(); $made[] = $txnB;
    $pdo->prepare("INSERT INTO payments (transaction_id,payment_reference,payment_method,amount,status,refund_status)
        VALUES (?,?,'fpx',100.00,'pending','none')")->execute([$txnB, $refB]);
    [$c,$b] = cancel($tok, $txnB);
    $t = $pdo->query("SELECT status, payment_status FROM transactions WHERE transaction_id=$txnB")->fetch(PDO::FETCH_ASSOC);
    $p = $pdo->query("SELECT status, refund_status FROM payments WHERE payment_reference='$refB'")->fetch(PDO::FETCH_ASSOC);
    echo "\n== B: no completed payment (unverified money) ==\n";
    check("order -> Dibatalkan", $t['status']==='Dibatalkan', $pass, $fail);
    check("B NOT claimed refunded (stays 'paid')", $t['payment_status']==='paid', $pass, $fail, "got: {$t['payment_status']}");
    check("B payment closed to failed + refund_status=failed (actionable)", $p['status']==='failed' && $p['refund_status']==='failed', $pass, $fail, json_encode($p));

    // ===== Scenario C: no payment row at all -> keep paid (was the old false 'refunded') =====
    $refC = 'E2EREFUND_C_' . bin2hex(random_bytes(4));
    $craft->execute([$buyerId, $sellerId, $productId, 1, $refC]);
    $txnC = (int)$pdo->lastInsertId(); $made[] = $txnC;
    [$c,$b] = cancel($tok, $txnC);
    $t = $pdo->query("SELECT status, payment_status FROM transactions WHERE transaction_id=$txnC")->fetch(PDO::FETCH_ASSOC);
    echo "\n== C: missing payment record ==\n";
    check("order -> Dibatalkan", $t['status']==='Dibatalkan', $pass, $fail);
    check("C NOT claimed refunded (stays 'paid' for manual recon)", $t['payment_status']==='paid', $pass, $fail, "got: {$t['payment_status']}");

    // ===== Scenario D: completed payment already 'processing' refund -> helper fails, stay paid =====
    $refD = 'E2EREFUND_D_' . bin2hex(random_bytes(4));
    $craft->execute([$buyerId, $sellerId, $productId, 1, $refD]);
    $txnD = (int)$pdo->lastInsertId(); $made[] = $txnD;
    $pdo->prepare("INSERT INTO payments (transaction_id,payment_reference,payment_method,amount,status,refund_status,paid_at)
        VALUES (?,?,'fpx',100.00,'completed','processing',NOW())")->execute([$txnD, $refD]);
    [$c,$b] = cancel($tok, $txnD);
    $t = $pdo->query("SELECT status, payment_status FROM transactions WHERE transaction_id=$txnD")->fetch(PDO::FETCH_ASSOC);
    $p = $pdo->query("SELECT status, refund_status FROM payments WHERE payment_reference='$refD'")->fetch(PDO::FETCH_ASSOC);
    echo "\n== D: refund not completed (already processing) ==\n";
    check("order -> Dibatalkan", $t['status']==='Dibatalkan', $pass, $fail);
    check("D NOT claimed refunded on refund failure (stays 'paid')", $t['payment_status']==='paid', $pass, $fail, "got: {$t['payment_status']}");
    check("D payment left completed/refund_status=processing (not falsely refunded)", $p['status']==='completed' && $p['refund_status']==='processing', $pass, $fail, json_encode($p));

} finally {
    foreach ($made as $id) {
        $pdo->prepare("DELETE FROM payments WHERE transaction_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$id]);
    }
    $pdo->prepare("UPDATE products SET stock=?, sold_count=? WHERE product_id=?")
        ->execute([$snap['stock'], $snap['sold_count'], $productId]);
    echo "\ncleanup: " . count($made) . " crafted orders removed; product $productId stock/sold_count restored\n";
}

echo "\n==================================\nRESULT: $pass passed, $fail failed\n==================================\n";
exit($fail === 0 ? 0 : 1);
