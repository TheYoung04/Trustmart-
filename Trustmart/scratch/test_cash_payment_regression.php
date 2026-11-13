<?php
/**
 * scratch/test_cash_payment_regression.php — LIVE E2E for the v12/v13 migrations.
 *
 * Guards the regression that shipped silently: product.php writes the v13-only
 * payment_status 'unpaid_cash', and admin.php / config/payment.php write and read
 * the v12-only 'pending_return' + return_shipments. When those migrations were not
 * applied, EVERY cash ("Tunai", the default checkout radio) order died with
 * HTTP 500 "Ralat pelayan." under sql_mode=STRICT_TRANS_TABLES.
 *
 * Drives the real HTTP endpoints (NOT direct DB inserts) against a running
 * Apache/preview server, then fully self-cleans (deletes crafted rows, restores
 * product stock/sold_count and the touched payment/dispute rows).
 *
 * Run:
 *   php scratch/test_cash_payment_regression.php http://localhost:8080
 *   php scratch/test_cash_payment_regression.php http://localhost/Trustmart
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only.\n"); }

$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, $d = '') {
    global $pass, $fail;
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d !== '' ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}

$jar = sys_get_temp_dir() . '/tm_cash_' . getmypid() . '.txt'; @unlink($jar);
function http($url, $post = null, $hdr = []) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,
        // Must follow redirects: under preview_router.php every top-level path
        // 302s to its /public/ equivalent, so a non-following GET returns an
        // empty 302 body and the csrf token would never be found.
        CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>5,
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
function jdecode($b) { $j = json_decode($b, true); return is_array($j) ? $j : []; }

@unlink($jar);
echo "Target: $base\n\n";

// ── Pick a buyer, and a product whose seller is not that buyer ───────────────
$buyerRow = $pdo->query("SELECT user_id, email FROM users WHERE role='pembeli' AND is_active=1
                          AND email NOT LIKE 'admin@%' ORDER BY user_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$prod = null;
// Reuse the SHARED order gate so this test can never drift from production logic
// (product.php:105 calls exactly this before accepting an order).
require_once $root . '/includes/ai_trust.php';
$cands = $pdo->query("SELECT p.product_id, p.seller_id, p.price, u.email AS seller_email
                        FROM products p
                        JOIN users u ON u.user_id = p.seller_id
                       WHERE p.status='Aktif' AND p.stock > 0 AND u.is_verified=1 AND u.is_active=1
                         AND p.seller_id <> {$buyerRow['user_id']}
                    ORDER BY p.product_id ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cands as $c) {
    if (!ai_trust_seller_hold($pdo, (int) $c['seller_id'], 'penjual')) { $prod = $c; break; }
}
if (!$buyerRow || !$prod) { die("No usable buyer/non-held in-stock verified product found.\n"); }

$productId  = (int) $prod['product_id'];
$sellerId   = (int) $prod['seller_id'];
$sellerEmail = $prod['seller_email'];
$snap = $pdo->query("SELECT stock, sold_count FROM products WHERE product_id=$productId")->fetch(PDO::FETCH_ASSOC);
echo "buyer=$buyerRow[email] product=$productId seller=$sellerId($sellerEmail) stock_snapshot={$snap['stock']}/{$snap['sold_count']}\n\n";

// ── Teardown runs on EVERY exit path, including fatals ───────────────────────
$craftedTxn = null;
register_shutdown_function(function () use ($pdo, $productId, $snap, &$craftedTxn, $jar) {
    echo "\n-- teardown --\n";
    try {
        if ($craftedTxn) {
            $pdo->exec("DELETE FROM cash_payments WHERE transaction_id = $craftedTxn");
            $pdo->exec("DELETE FROM transactions WHERE transaction_id = $craftedTxn");
            $pdo->exec("UPDATE products SET stock={$snap['stock']}, sold_count={$snap['sold_count']} WHERE product_id=$productId");
            echo "  removed crafted txn $craftedTxn, restored product $productId\n";
        }
        @unlink($jar);
    } catch (Throwable $e) { echo "  teardown ERROR: {$e->getMessage()}\n"; }
});

// ── 0. Schema preconditions (the migrations that were missing) ───────────────
echo "[0] schema\n";
$t = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='cash_payments'")->fetchColumn();
check('v13 table cash_payments exists', (int)$t === 1);
$t = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='return_shipments'")->fetchColumn();
check('v12 table return_shipments exists', (int)$t === 1);
$e = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='transactions' AND column_name='payment_status'")->fetchColumn();
check("v13 ENUM has unpaid_cash AND paid_cash", strpos($e, 'unpaid_cash') !== false && strpos($e, 'paid_cash') !== false, $e);
$e2 = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payments' AND column_name='refund_status'")->fetchColumn();
check("v12 ENUM has pending_return", strpos($e2, 'pending_return') !== false, $e2);

// ── 1. Login as buyer ────────────────────────────────────────────────────────
echo "\n[1] buyer login\n";
[$c, $b] = http("$base/index.php"); $tok = token_from($b);
check('fetched public index + csrf token', $c === 200 && $tok !== '');
[$c, $b] = http("$base/auth/login.php", ['email'=>$buyerRow['email'], 'password'=>'password123', '_csrf_token'=>$tok]);
$j = jdecode($b);
check('buyer logged in', $c === 200 && !empty($j['success']), "http=$c " . ($j['message'] ?? ''));

// ── 2. THE REGRESSION: place a cash order through the real endpoint ──────────
echo "\n[2] POST product.php?action=make_order (payment_type=cash)\n";
[$c, $b] = http("$base/product.php?id=$productId"); $tok = token_from($b);
[$c, $b] = http("$base/product.php?action=make_order&id=$productId",
    ['quantity'=>1, 'payment_method'=>'Tunai', 'payment_type'=>'cash', '_csrf_token'=>$tok]);
$j = jdecode($b);
check('cash order returns HTTP 200 (was 500 before v13)', $c === 200, "http=$c");
check('cash order reports success (message, not "Ralat pelayan.")', !empty($j['success']), substr($b, 0, 120));
$craftedTxn = (int) $pdo->query("SELECT MAX(transaction_id) FROM transactions WHERE product_id=$productId AND payment_type='cash' AND payment_status='unpaid_cash'")->fetchColumn();
check('cash transaction persisted', $craftedTxn > 0, "txn_id=$craftedTxn");
$row = $pdo->query("SELECT payment_status, status, payment_type FROM transactions WHERE transaction_id=$craftedTxn")->fetch(PDO::FETCH_ASSOC);
check("payment_status is 'unpaid_cash' (round-trips, not truncated)", ($row['payment_status'] ?? '') === 'unpaid_cash', json_encode($row));
$cp = $pdo->query("SELECT confirmed_by_seller, amount_received FROM cash_payments WHERE transaction_id=$craftedTxn")->fetch(PDO::FETCH_ASSOC);
check('after_cash_transaction_insert trigger created the cash_payments row', is_array($cp) && (int)$cp['confirmed_by_seller'] === 0, json_encode($cp));
$p = $pdo->query("SELECT stock, sold_count FROM products WHERE product_id=$productId")->fetch(PDO::FETCH_ASSOC);
check('stock decremented by 1', (int)$p['stock'] === (int)$snap['stock'] - 1, "{$snap['stock']} -> {$p['stock']}");

// ── 3. Seller confirms the cash payment (the UPDATE cash_payments path) ──────
echo "\n[3] seller confirm_cash_payment\n";
[$c, $b] = http("$base/auth/logout.php");
[$c, $b] = http("$base/index.php"); $tok = token_from($b);
[$c, $b] = http("$base/auth/login.php", ['email'=>$sellerEmail, 'password'=>'password123', '_csrf_token'=>$tok]);
check('seller logged in', $c === 200 && !empty(jdecode($b)['success']), "http=$c");
[$c, $b] = http("$base/dashboard/seller.php"); $tok = token_from($b);

// [3a] UI WIRING GUARD. The endpoint has existed since v13 but had no client
// control, so a seller facing the 422 from update_order_status had literally
// nowhere to record receiving cash and the order was stranded. Assert the real
// page renders the affordance before driving the endpoint directly.
check('dashboard renders "Sah Terima Tunai" for the unpaid_cash order',
    str_contains($b, "openCashModal($craftedTxn") && str_contains($b, 'Sah Terima Tunai'),
    "txn=$craftedTxn");
check('dashboard disables "Sahkan" until cash receipt is recorded',
    (bool) preg_match('/disabled[^>]*>\s*Sahkan/s', $b), 'no disabled Sahkan found');
check('cash modal + submit handler present in page',
    str_contains($b, 'id="modal-cash"') && str_contains($b, 'function submitCashConfirm'),
    'modal or handler missing');
[$c, $b] = http("$base/dashboard/seller.php?action=confirm_cash_payment",
    ['transaction_id'=>$craftedTxn, 'amount_received'=>$prod['price'],
     'payment_method_detail'=>'Hand-to-hand', 'seller_notes'=>'regression probe', '_csrf_token'=>$tok]);
$j = jdecode($b);
check('confirm_cash_payment returns 200 (UPDATE cash_payments worked)', $c === 200, "http=$c");
check('confirm reported success', !empty($j['success']), substr($b, 0, 120));
$st = $pdo->query("SELECT payment_status FROM transactions WHERE transaction_id=$craftedTxn")->fetchColumn();
check("payment_status advanced to 'paid_cash'", $st === 'paid_cash', $st);
$cp = $pdo->query("SELECT confirmed_by_seller, seller_notes FROM cash_payments WHERE transaction_id=$craftedTxn")->fetch(PDO::FETCH_ASSOC);
check('cash_payments row updated, notes persisted', is_array($cp) && (int)$cp['confirmed_by_seller'] === 1 && ($cp['seller_notes'] ?? '') === 'regression probe', json_encode($cp));

// [3b] After receipt is recorded the UI must flip: cash button gone, live
// "Sahkan" in its place — i.e. the order is no longer stranded.
[$c, $b] = http("$base/dashboard/seller.php");
check('after confirm: cash button removed for this order',
    !str_contains($b, "openCashModal($craftedTxn"), "txn=$craftedTxn still offers cash modal");
check('after confirm: live "Sahkan" button present',
    str_contains($b, "updateOrder($craftedTxn,'Disahkan')"), 'no live Sahkan for txn');

// ── 4. Guards must still reject bad input ────────────────────────────────────
echo "\n[4] negative cases\n";
[$c, $b] = http("$base/dashboard/seller.php?action=confirm_cash_payment",
    ['transaction_id'=>$craftedTxn, 'amount_received'=>$prod['price'], '_csrf_token'=>$tok]);
check('double confirm rejected with 422', $c === 422, "http=$c");
[$c, $b] = http("$base/dashboard/seller.php?action=confirm_cash_payment",
    ['transaction_id'=>$craftedTxn, 'amount_received'=>1, '_csrf_token'=>$tok]);
check('wrong amount rejected with 422', $c === 422, "http=$c");

// ── 5. v12 refund-hold primitives (schema-level; admin UI has its own defect) ─
echo "\n[5] v12 return-hold primitives\n";
$pay = $pdo->query("SELECT payment_id FROM payments WHERE refund_status='none' AND status='completed' LIMIT 1")->fetchColumn();
if ($pay) {
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE payments SET refund_status='pending_return' WHERE payment_id=$pay");
        $back = $pdo->query("SELECT refund_status FROM payments WHERE payment_id=$pay")->fetchColumn();
        check("'pending_return' round-trips (was ERROR 1265)", $back === 'pending_return', $back);
        throw new RuntimeException('rollback-only');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'rollback-only') throw $e;
        $pdo->rollBack();
    }
    $after = $pdo->query("SELECT refund_status FROM payments WHERE payment_id=$pay")->fetchColumn();
    check('payment refund_status restored by rollback', $after === 'none', $after);
} else { check('completed payment available to probe refund_status', false, 'none found'); }

$rsCols = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='return_shipments' AND column_name IN ('tracking_number','carrier','verified_at','status')")->fetchColumn();
check('return_shipments has the columns admin_returns.php selects', (int)$rsCols === 4, "found $rsCols/4");
$dCol = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='disputes' AND column_name='reason'")->fetchColumn();
check('disputes.reason absent (schema invariant)', (int)$dCol === 0, "dCol=$dCol");
$arsrc = file_get_contents(__DIR__ . '/../dashboard/admin_returns.php');
check('[DEFECT-001 FIX GUARD] admin_returns.php no longer selects d.reason', stripos($arsrc, 'd.reason') === false,
      'still found d.reason');

// ── summary ─────────────────────────────────────────────────────────────────
echo "\n==============================\nPASS: $pass  FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
