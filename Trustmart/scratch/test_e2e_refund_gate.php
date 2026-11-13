<?php
/**
 * scratch/test_e2e_refund_gate.php — LIVE E2E for DEFECT-002.
 *
 * Proves the admin manual refund actions now consult the authoritative
 * can_proceed_refund() return-gate:
 *   A1 mark_refund_completed on a 'requested' refund whose dispute REQUIRES a
 *      physical return that is NOT yet verified  -> blocked (409), no state change.
 *      (This is the real bypass: the old refund_status allowlist WOULD have let
 *       a 'requested' refund complete before the return was verified.)
 *   A2 retry_refund on a 'failed' refund with an unverified required return
 *      -> blocked (409). (process_payment_refund only guards 'pending_return',
 *       so 'failed' would otherwise be re-driven to the gateway.)
 *   B  after the return is marked 'verified'       -> mark_refund_completed succeeds.
 *   C  a no-return-required refund ('requested')   -> succeeds (gate not over-blocking).
 *
 * Self-cleaning: every fixture txn/payment/dispute/return_shipment is removed and
 * both seller & buyer trust profiles are recomputed from the now-clean tables.
 * Run: C:\xampp\php\php.exe scratch/test_e2e_refund_gate.php [base]
 */
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/config/payment.php';

$pass = 0; $fail = 0;
function check($n, $c, $d = '') {
    global $pass, $fail;
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}
$jar = sys_get_temp_dir() . '/tm_refundgate.txt'; @unlink($jar);

function http($url, $post = null, $hdr = []) {
    global $jar; $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar, CURLOPT_TIMEOUT=>25]);
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
// Read the authoritative procedure result for a payment (mirrors admin.php's helper).
function gate($pdo, $payment_id) {
    $s = $pdo->prepare('CALL can_proceed_refund(?, @c, @r)');
    $s->execute([$payment_id]); $s->closeCursor();
    $row = $pdo->query('SELECT @c AS c, @r AS r')->fetch(PDO::FETCH_ASSOC);
    return [(int)($row['c'] ?? 0), (string)($row['r'] ?? '')];
}

echo "Target: $base\n";
$adminEmail = $pdo->query("SELECT email FROM users WHERE role='pentadbir' LIMIT 1")->fetchColumn();
[, $page] = http("$base/public/"); $tok = token_from($page);
if ($tok === '') { check("CSRF token from landing", false); echo "ABORT\n"; exit(1); }
[, $lb] = http("$base/auth/login.php", ['email'=>$adminEmail,'password'=>'password123','_csrf_token'=>$tok],
     ['X-CSRF-Token: '.$tok, 'Accept: application/json']);
check("admin login", (json_decode($lb, true)['success'] ?? false) === true, "email=$adminEmail");
[, $dash] = http("$base/dashboard/admin.php"); $tok = token_from($dash) ?: $tok;

$buyer  = (int)$pdo->query("SELECT user_id FROM users WHERE role='pembeli' AND is_active=1 LIMIT 1")->fetchColumn();
$prod   = $pdo->query("SELECT product_id, seller_id FROM products ORDER BY product_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$seller = (int)$prod['seller_id']; $product = (int)$prod['product_id'];
echo "admin=$adminEmail buyer=$buyer seller=$seller product=$product\n";

$fixtures = [];  // each: [txn, payment, dispute, return]
function new_txn($pdo, $buyer, $seller, $product) {
    $ref = 'TMGATE_T' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO transactions (buyer_id, seller_id, product_id, quantity, total_amount,
            payment_method, status, payment_type, payment_status, payment_reference, created_at, completed_at)
        VALUES (?,?,?,1,100.00,'Pindahan Bank','Selesai','online','paid', ?, NOW(), NOW())")
        ->execute([$buyer, $seller, $product, $ref]);
    return (int)$pdo->lastInsertId();
}
// Craft a full refund fixture: completed payment in $refundStatus + a settled-buyer-
// favourable dispute that optionally requires a return + a matching return shipment.
function craft($pdo, $buyer, $seller, $product, $refundStatus, $returnRequired) {
    $txn = new_txn($pdo, $buyer, $seller, $product);
    $payRef = 'TMGATE_P' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO payments (transaction_id, payment_reference, payment_method, amount,
            currency, status, refund_status, refund_method, refund_attempts, paid_at)
        VALUES (?, ?, 'fpx', 100.00, 'MYR', 'completed', ?, 'admin', 0, NOW())")
        ->execute([$txn, $payRef, $refundStatus]);
    $pay = (int)$pdo->lastInsertId();
    $dRef = 'dispute probe ' . $payRef;
    $pdo->prepare("INSERT INTO disputes (transaction_id, filed_by, title, description, severity, status,
            settlement_status, settlement_return_required, settlement_refund_amount)
        VALUES (?,?, 'Ujian gate', ? , 'Sederhana', 'Selesai', 'queued', ?, 100.00)")
        ->execute([$txn, $buyer, $dRef, $returnRequired]);
    $disp = (int)$pdo->lastInsertId();
    $ret = null;
    if ($returnRequired) {
        $pdo->prepare("INSERT INTO return_shipments (dispute_id, transaction_id, status, notes)
            VALUES (?, ?, 'pending', '__gate_probe__')")->execute([$disp, $txn]);
        $ret = (int)$pdo->lastInsertId();
    }
    return [$txn, $pay, $disp, $ret];
}
function pay_row($pdo, $pay) {
    return $pdo->query("SELECT refund_status FROM payments WHERE payment_id=$pay")->fetch(PDO::FETCH_ASSOC);
}
function txn_pay_status($pdo, $txn) {
    return $pdo->query("SELECT payment_status FROM transactions WHERE transaction_id=$txn")->fetchColumn();
}

try {
    // ===== A1: mark_refund_completed blocked on unverified required return =====
    [$txn, $pay] = $fixtures[] = craft($pdo, $buyer, $seller, $product, 'requested', 1);
    echo "\n== A1: mark_refund_completed on 'requested' + unverified return ==\n";
    [$c, $b] = http("$base/dashboard/admin.php?action=mark_refund_completed",
        ['payment_id'=>$pay, 'notes'=>'gate test', '_csrf_token'=>$tok], ['X-CSRF-Token: '.$tok]);
    $j = json_decode($b, true);
    check("A1 gate returns can_proceed=0", gate($pdo, $pay)[0] === 0, "reason=".gate($pdo,$pay)[1]);
    check("A1 endpoint BLOCKED (409)", $c === 409 && ($j['success'] ?? true) === false, "code=$c body=".substr($b,0,140));
    check("A1 refund NOT completed (still 'requested')", pay_row($pdo,$pay)['refund_status'] === 'requested', pay_row($pdo,$pay)['refund_status']);
    check("A1 transaction NOT flipped to refunded", (string)txn_pay_status($pdo,$txn) === 'paid', (string)txn_pay_status($pdo,$txn));

    // ===== A2: retry_refund blocked on 'failed' + unverified required return =====
    [$txnF, $payF] = $fixtures[] = craft($pdo, $buyer, $seller, $product, 'failed', 1);
    echo "\n== A2: retry_refund on 'failed' + unverified return ==\n";
    [$c2, $b2] = http("$base/dashboard/admin.php?action=retry_refund",
        ['payment_id'=>$payF, '_csrf_token'=>$tok], ['X-CSRF-Token: '.$tok]);
    $j2 = json_decode($b2, true);
    check("A2 endpoint BLOCKED (409)", $c2 === 409 && ($j2['success'] ?? true) === false, "code=$c2 body=".substr($b2,0,140));
    check("A2 refund status unchanged ('failed')", pay_row($pdo,$payF)['refund_status'] === 'failed', pay_row($pdo,$payF)['refund_status']);

    // ===== B: after the return is VERIFIED, completion is allowed =====
    echo "\n== B: return verified -> allowed ==\n";
    $pdo->prepare("UPDATE return_shipments SET status='verified', verified_at=CURRENT_TIMESTAMP WHERE dispute_id=?")->execute([$fixtures[0][2]]);
    check("B gate returns can_proceed=1 after verify", gate($pdo, $pay)[0] === 1, "reason=".gate($pdo,$pay)[1]);
    [$c3, $b3] = http("$base/dashboard/admin.php?action=mark_refund_completed",
        ['payment_id'=>$pay, 'notes'=>'verified gate test', '_csrf_token'=>$tok], ['X-CSRF-Token: '.$tok]);
    $j3 = json_decode($b3, true);
    check("B endpoint SUCCEEDED", $c3 === 200 && ($j3['success'] ?? false) === true, "code=$c3 body=".substr($b3,0,140));
    check("B refund now 'completed'", pay_row($pdo,$pay)['refund_status'] === 'completed', pay_row($pdo,$pay)['refund_status']);

    // ===== C: no-return-required refund is allowed (gate not over-blocking) =====
    [$txnC, $payC] = $fixtures[] = craft($pdo, $buyer, $seller, $product, 'requested', 0);
    echo "\n== C: no return required -> allowed ==\n";
    check("C gate returns can_proceed=1 (no return req)", gate($pdo, $payC)[0] === 1, "reason=".gate($pdo,$payC)[1]);
    [$c4, $b4] = http("$base/dashboard/admin.php?action=mark_refund_completed",
        ['payment_id'=>$payC, 'notes'=>'no-return control', '_csrf_token'=>$tok], ['X-CSRF-Token: '.$tok]);
    $j4 = json_decode($b4, true);
    check("C endpoint SUCCEEDED", $c4 === 200 && ($j4['success'] ?? false) === true, "code=$c4 body=".substr($b4,0,140));

    // ===== negative auth sanity: anon POST must not act =====
    echo "\n== sanity: anonymous mark_refund_completed is rejected ==\n";
    @unlink($jar);
    [$c5, $b5] = http("$base/dashboard/admin.php?action=mark_refund_completed", ['payment_id'=>$payC]);
    $anonOk = ($c5 === 200 && stripos($b5, '"success":true') !== false);
    check("anon cannot drive mark_refund_completed", $anonOk === false, "code=$c5");

} finally {
    foreach ($fixtures as $f) {
        [$txn, $pay, $disp, $ret] = $f;
        if ($ret)  $pdo->prepare("DELETE FROM return_shipments WHERE return_id=?")->execute([$ret]);
        if ($disp) $pdo->prepare("DELETE FROM disputes WHERE dispute_id=?")->execute([$disp]);
        $pdo->prepare("DELETE FROM payments WHERE payment_id=?")->execute([$pay]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$txn]);
    }
    try { $pdo->prepare("CALL recalculate_trust_score(?)")->execute([$seller]); } catch (Exception $e) {}
    try { $pdo->prepare("CALL recalculate_buyer_trust_score(?)")->execute([$buyer]); } catch (Exception $e) {}
    echo "\ncleanup: ".count($fixtures)." fixture(s) removed; seller #$seller & buyer #$buyer trust recomputed\n";
}

echo "\n=================================\nRESULT: $pass passed, $fail failed\n=================================\n";
exit($fail === 0 ? 0 : 1);
