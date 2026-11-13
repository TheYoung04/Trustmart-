<?php
/**
 * scratch/test_e2e_price_snapshot.php — LIVE E2E for Issue #9 (product price
 * edits must NOT invalidate already-created orders). Craft an order with a
 * snapshot total, then RAISE the listing's current price to simulate the seller
 * editing it after checkout, and confirm pay.php still accepts the order and
 * charges from the snapshot, not the mutable listing. Self-cleans (deletes
 * crafted rows, restores the original product price). Uses password123 buyer.
 */
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/config/payment.php';

$pass = 0; $fail = 0; $skip = 0;
function check($n, $c, &$pass, &$fail, $d = '') {
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}
function skip($n, &$skip, $d = '') { echo "  [SKIP] $n  ($d)\n"; $skip++; }
$jar = sys_get_temp_dir() . '/tm_pricesnap.txt'; @unlink($jar);

function get_loc($url) {
    global $jar; $loc = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar, CURLOPT_TIMEOUT=>20,
        CURLOPT_HEADERFUNCTION=>function($ch,$h) use (&$loc){ if(preg_match('/^Location:\s*(.+)$/i',$h,$m))$loc=trim($m[1]); return strlen($h);}]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $loc, (string)$body];
}
function http($url, $post=null, $hdr=[]) {
    global $jar; $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar, CURLOPT_TIMEOUT=>20]);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post!==null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$body];
}
function token_from($b) {
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $b, $m)) return $m[1];
    if (preg_match('/name="_csrf_token" value="([^"]+)"/', $b, $m)) return $m[1];
    return '';
}

echo "Target: $base\n";
$buyerEmail = $pdo->query("SELECT email FROM users WHERE role='pembeli' AND is_active=1 LIMIT 1")->fetchColumn();
$buyerId = (int)$pdo->query("SELECT user_id FROM users WHERE email=".$pdo->quote($buyerEmail))->fetchColumn();
$prod = $pdo->query("SELECT product_id, seller_id, price FROM products ORDER BY product_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$productId = (int)$prod['product_id']; $sellerId = (int)$prod['seller_id']; $origPrice = (float)$prod['price'];
$snapshot = 88.00;   // the price the buyer agreed to at checkout
$hike     = 999.00;  // seller raises the listing AFTER checkout
echo "buyer=$buyerEmail(id=$buyerId) product=$productId origPrice=$origPrice snapshot=$snapshot -> hike=$hike\n";

// Login
[, $page] = http("$base/public/"); $tok = token_from($page);
if ($tok === '') { check("CSRF token from landing", false, $pass, $fail); echo "ABORT\n"; exit(1); }
http("$base/auth/login.php", ['email'=>$buyerEmail,'password'=>'password123','_csrf_token'=>$tok],
     ['X-CSRF-Token: '.$tok, 'Accept: application/json']);
[, $dash] = http("$base/dashboard/buyer.php"); $tok = token_from($dash) ?: $tok;

$made = [];
$craft = $pdo->prepare(
    "INSERT INTO transactions (buyer_id, seller_id, product_id, quantity, total_amount,
        payment_method, status, payment_type, payment_status, payment_reference, created_at)
     VALUES (?,?,?,1,?, 'FPX','Menunggu','online','unpaid', ?, NOW())");

// Simulate the seller editing the listing price after checkout.
$pdo->prepare("UPDATE products SET price=? WHERE product_id=?")->execute([$hike, $productId]);

try {
    // ===== A: legitimate order (snapshot != current price) is NOT invalidated =====
    $refA = 'E2E9_A_'.bin2hex(random_bytes(3));
    $craft->execute([$buyerId, $sellerId, $productId, $snapshot, $refA]);
    $txnA = (int)$pdo->lastInsertId(); $made[] = $txnA;
    [$c, $loc, $b] = get_loc("$base/payment/pay.php?txn=$txnA");
    echo "\n== A: order survives a post-checkout price hike ==\n";
    check("A accepted (pay form shown, not rejected)", $c === 200 && strpos($b, 'Pilih Kaedah Pembayaran') !== false, $pass, $fail, "code=$c loc=$loc");
    check("A does NOT surface the new listing price ($hike)", strpos($b, '999.00') === false, $pass, $fail);
    check("A shows the snapshot-derived amount", strpos($b, number_format(calculate_payment_amount($snapshot), 2)) !== false, $pass, $fail, "expect ".number_format(calculate_payment_amount($snapshot),2));

    // ===== B: the charge is built from the SNAPSHOT, not the mutable listing =====
    $refB = 'E2E9_B_'.bin2hex(random_bytes(3));
    $craft->execute([$buyerId, $sellerId, $productId, $snapshot, $refB]);
    $txnB = (int)$pdo->lastInsertId(); $made[] = $txnB;
    if (!payment_simulation_allowed()) {
        skip("B POST amount check (needs TRUSTMART_DEV_MODE for simulator)", $skip);
    } else {
        [, $formB] = get_loc("$base/payment/pay.php?txn=$txnB"); $tokB = token_from($formB) ?: $tok;
        [$c] = http("$base/payment/pay.php?txn=$txnB",
            ['payment_method'=>'fpx', '_csrf_token'=>$tokB], ['X-CSRF-Token: '.$tokB]);
        $pay = $pdo->query("SELECT amount, status FROM payments WHERE transaction_id=$txnB ORDER BY payment_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        echo "\n== B: created payment uses the snapshot ==\n";
        check("B created a payment via gateway", $c === 302 && $pay, $pass, $fail, "code=$c");
        $snapAmt = (float) calculate_payment_amount($snapshot);
        $hikeAmt = (float) calculate_payment_amount($hike);
        check("B charged the SNAPSHOT amount (not the hiked price)", $pay && abs((float)$pay['amount'] - $snapAmt) < 0.01, $pass, $fail, "amount={$pay['amount']} snap=$snapAmt hike=$hikeAmt");
        check("B did NOT charge the hiked-price amount", $pay && abs((float)$pay['amount'] - $hikeAmt) > 0.01, $pass, $fail);
    }

    // ===== C: corrupt / non-positive snapshot is STILL rejected =====
    $refC = 'E2E9_C_'.bin2hex(random_bytes(3));
    $craft->execute([$buyerId, $sellerId, $productId, 0.00, $refC]);
    $txnC = (int)$pdo->lastInsertId(); $made[] = $txnC;
    [$c, $loc, $b] = get_loc("$base/payment/pay.php?txn=$txnC");
    echo "\n== C: corrupt snapshot guard retained ==\n";
    check("C zero/negative snapshot rejected -> dashboard", $c === 302 && strpos($loc, 'dashboard/buyer.php') !== false, $pass, $fail, "code=$c loc=$loc");

} finally {
    foreach ($made as $id) {
        $pdo->prepare("DELETE FROM payments WHERE transaction_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$id]);
    }
    $pdo->prepare("UPDATE products SET price=? WHERE product_id=?")->execute([$origPrice, $productId]);
    echo "\ncleanup: ".count($made)." crafted orders removed; product $productId price restored to $origPrice\n";
}

echo "\n=================================\nRESULT: $pass passed, $fail failed, $skip skipped\n=================================\n";
exit($fail === 0 ? 0 : 1);
