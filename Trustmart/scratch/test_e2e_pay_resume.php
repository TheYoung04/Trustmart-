<?php
/**
 * scratch/test_e2e_pay_resume.php — LIVE E2E for Issue #8 (abandoned payment
 * resume / retry). Drives the real payment/pay.php over HTTP against the running
 * Apache+MySQL, plus scrapes the buyer dashboard for the new buttons.
 * Fully self-cleans (deletes crafted rows; no stock touched since orders are
 * crafted directly, not via checkout). Uses password123 for the demo buyer.
 */
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/config/payment.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail, $d = '') {
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}
$jar = sys_get_temp_dir() . '/tm_payresume.txt'; @unlink($jar);

// GET that also returns the Location header (redirects are NOT followed).
function get_loc($url) {
    global $jar;
    $loc = ''; $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => function ($ch, $hdr) use (&$loc) {
            if (preg_match('/^Location:\s*(.+)$/i', $hdr, $m)) $loc = trim($m[1]);
            return strlen($hdr);
        },
    ]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $loc, (string)$body];
}
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
$timeout = (int) PAYMENT_TIMEOUT_MINUTES;

// ── Buyer + product (pay gate requires total_amount == price*qty) ──
$buyerEmail = $pdo->query("SELECT email FROM users WHERE role='pembeli' AND is_active=1 LIMIT 1")->fetchColumn();
$buyerId = (int) $pdo->query("SELECT user_id FROM users WHERE email=" . $pdo->quote($buyerEmail))->fetchColumn();
$otherBuyer = (int) $pdo->query("SELECT user_id FROM users WHERE role='pembeli' AND user_id <> $buyerId LIMIT 1")->fetchColumn();
$prod = $pdo->query("SELECT product_id, seller_id, price FROM products ORDER BY product_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$productId = (int)$prod['product_id']; $sellerId = (int)$prod['seller_id']; $price = (float)$prod['price'];
echo "buyer=$buyerEmail(id=$buyerId) other=$otherBuyer product=$productId price=$price\n";

// ── Login ──
[, $page] = http("$base/public/"); $tok = token_from($page);
if ($tok === '') { check("CSRF token from landing", false, $pass, $fail); echo "ABORT\n"; exit(1); }
http("$base/auth/login.php", ['email'=>$buyerEmail,'password'=>'password123','_csrf_token'=>$tok],
     ['X-CSRF-Token: '.$tok, 'Accept: application/json']);
[, $dash] = http("$base/dashboard/buyer.php"); $tok = token_from($dash) ?: $tok;
echo "logged in (dashboard bytes=" . strlen($dash) . ")\n";

$made = [];
$craft = $pdo->prepare(
    "INSERT INTO transactions (buyer_id, seller_id, product_id, quantity, total_amount,
             payment_method, status, payment_type, payment_status, payment_reference, created_at)
     VALUES (?,?,?,?,?, 'FPX','Menunggu','online',?, ?, NOW())");

try {
    // ===== A: pending with an ACTIVE gateway bill -> RESUME (302 to that URL) =====
    $refA = 'E2E8_A_' . bin2hex(random_bytes(3));
    $craft->execute([$buyerId, $sellerId, $productId, 1, $price, 'pending', $refA]);
    $txnA = (int)$pdo->lastInsertId(); $made[] = $txnA;
    $urlA = "$base/payment/gateway_sim.php?ref=$refA";
    $pdo->prepare("INSERT INTO payments (transaction_id,payment_reference,payment_method,amount,status,payment_url,created_at)
        VALUES (?,?,'fpx',?, 'pending', ?, NOW())")->execute([$txnA, $refA, $price, $urlA]);
    [$c, $loc, $b] = get_loc("$base/payment/pay.php?txn=$txnA");
    echo "\n== A: resume active pending bill ==\n";
    check("A 302 redirect", $c === 302, $pass, $fail, "code=$c");
    check("A resumes the EXISTING gateway URL", $loc === $urlA, $pass, $fail, "loc=$loc");
    $pc = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE transaction_id=$txnA")->fetchColumn();
    check("A no duplicate payment created", $pc === 1, $pass, $fail, "count=$pc");

    // ===== B: pending but bill EXPIRED -> fall through to method form (no stale redirect) =====
    $refB = 'E2E8_B_' . bin2hex(random_bytes(3));
    $craft->execute([$buyerId, $sellerId, $productId, 1, $price, 'pending', $refB]);
    $txnB = (int)$pdo->lastInsertId(); $made[] = $txnB;
    $urlB = "$base/payment/gateway_sim.php?ref=$refB";
    $pdo->prepare("INSERT INTO payments (transaction_id,payment_reference,payment_method,amount,status,payment_url,created_at)
        VALUES (?,?,'fpx',?, 'pending', ?, DATE_SUB(NOW(), INTERVAL " . ($timeout + 5) . " MINUTE))")
        ->execute([$txnB, $refB, $price, $urlB]);
    [$c, $loc, $b] = get_loc("$base/payment/pay.php?txn=$txnB");
    echo "\n== B: expired pending bill ==\n";
    check("B does NOT redirect to stale bill", $c !== 302 || strpos($loc, $urlB) === false, $pass, $fail, "code=$c loc=$loc");
    check("B renders fresh method form (200)", $c === 200 && strpos($b, 'Pilih Kaedah Pembayaran') !== false, $pass, $fail, "code=$c");

    // ===== C: failed attempt -> retry served (form), and dashboard shows buttons =====
    $refC = 'E2E8_C_' . bin2hex(random_bytes(3));
    $craft->execute([$buyerId, $sellerId, $productId, 1, $price, 'failed', $refC]);
    $txnC = (int)$pdo->lastInsertId(); $made[] = $txnC;
    [$c, $loc, $b] = get_loc("$base/payment/pay.php?txn=$txnC");
    echo "\n== C: retry a failed attempt ==\n";
    check("C accepted (form shown, not rejected)", $c === 200 && strpos($b, 'Pilih Kaedah Pembayaran') !== false, $pass, $fail, "code=$c");

    // ===== D: NOT owned by this buyer -> revalidated away (redirect to dashboard) =====
    $refD = 'E2E8_D_' . bin2hex(random_bytes(3));
    $craft->execute([$otherBuyer, $sellerId, $productId, 1, $price, 'pending', $refD]);
    $txnD = (int)$pdo->lastInsertId(); $made[] = $txnD;
    $pdo->prepare("INSERT INTO payments (transaction_id,payment_reference,payment_method,amount,status,payment_url,created_at)
        VALUES (?,?,'fpx',?, 'pending', ?, NOW())")->execute([$txnD, $refD, $price, "$base/payment/gateway_sim.php?ref=$refD"]);
    [$c, $loc, $b] = get_loc("$base/payment/pay.php?txn=$txnD");
    echo "\n== D: ownership revalidation ==\n";
    check("D other buyer's order rejected -> dashboard", $c === 302 && strpos($loc, 'dashboard/buyer.php') !== false, $pass, $fail, "code=$c loc=$loc");

    // ===== E: already-paid order -> state revalidation (no resume) =====
    $refE = 'E2E8_E_' . bin2hex(random_bytes(3));
    $craft->execute([$buyerId, $sellerId, $productId, 1, $price, 'paid', $refE]);
    $txnE = (int)$pdo->lastInsertId(); $made[] = $txnE;
    [$c, $loc, $b] = get_loc("$base/payment/pay.php?txn=$txnE");
    echo "\n== E: state revalidation (paid) ==\n";
    check("E paid order cannot be resumed -> dashboard", $c === 302 && strpos($loc, 'dashboard/buyer.php') !== false, $pass, $fail, "code=$c loc=$loc");

    // ===== F: dashboard surfaces resume + retry buttons =====
    [, $dash2] = http("$base/dashboard/buyer.php");
    echo "\n== F: dashboard action buttons ==\n";
    check("F shows 'Teruskan Pembayaran' (resume pending)", strpos($dash2, 'Teruskan Pembayaran') !== false, $pass, $fail);
    check("F shows 'Cuba Semula Pembayaran' (retry failed)", strpos($dash2, 'Cuba Semula Pembayaran') !== false, $pass, $fail);

} finally {
    foreach ($made as $id) {
        $pdo->prepare("DELETE FROM payments WHERE transaction_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$id]);
    }
    echo "\ncleanup: " . count($made) . " crafted orders + payments removed\n";
}

echo "\n=================================\nRESULT: $pass passed, $fail failed\n=================================\n";
exit($fail === 0 ? 0 : 1);
