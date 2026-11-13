<?php
/**
 * scratch/test_live_checkout.php
 *
 * LIVE end-to-end proof of the ONLINE checkout over real HTTP against the
 * running server — exercising the W1 refactor (gateway_sim no longer writes
 * payment state directly; every terminal transition goes through the single
 * authoritative writer apply_payment_gateway_result()).
 *
 * Flow: make_order(online) -> pay.php(method) -> gateway_sim(bank) -> return.php
 * Asserts the DB actually reaches payments=completed + transactions=paid,
 * that exactly ONE payment row reached a terminal state, and that the audit
 * blob was written by the shared writer. Then fully self-cleans.
 *
 * Run:  php scratch/test_live_checkout.php            (default Apache  /Trustmart)
 *       php scratch/test_live_checkout.php http://localhost:8080   (preview server)
 * Needs: server up + MySQL running. Uses password123 for the demo buyer.
 */

$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail, $d = '') {
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}
$jar = sys_get_temp_dir() . '/tm_live_co.txt';
@unlink($jar);
function http($url, $post = null, $hdr = []) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,     // we steer redirects manually
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc  = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$code, (string)$body, $loc];
}
// Session CSRF token is stable, so grab it once and reuse via header + field.
function token_from($body) {
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m)) return $m[1];
    if (preg_match('/name="_csrf_token" value="([^"]+)"/', $body, $m)) return $m[1];
    return '';
}

echo "Target: $base\n";

// ── Pick a buyable product whose seller is NOT under an AI hold ─────────
$prod = $pdo->query(
    "SELECT p.product_id, p.seller_id, p.price FROM products p
       JOIN users u ON u.user_id = p.seller_id
       LEFT JOIN trust_profiles tp ON tp.user_id = p.seller_id AND tp.profile_role='penjual'
      WHERE p.status='Aktif' AND p.stock > 0 AND u.is_verified=1 AND u.is_active=1
        AND (tp.fraud_risk IS NULL OR tp.fraud_risk < 50 OR tp.ai_hold = 0)
      ORDER BY p.product_id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (!$prod) { echo "No suitable product found.\n"; exit(1); }
$sellerId = (int)$prod['seller_id'];
$productId = (int)$prod['product_id'];
echo "product=$productId seller=$sellerId price={$prod['price']}\n";

// A buyer who is not the seller.
$buyerEmail = $pdo->query(
    "SELECT email FROM users WHERE role='pembeli' AND is_active=1 AND user_id <> $sellerId LIMIT 1"
)->fetchColumn();

// ── Login as the buyer ──────────────────────────────────────────────────
[, $page] = http("$base/public/");
$tok = token_from($page);
if ($tok === '') { check("got CSRF token from landing", false, $pass, $fail); echo "ABORT\n"; exit(1); }
[$c] = http("$base/auth/login.php",
    ['email' => $buyerEmail, 'password' => 'password123', '_csrf_token' => $tok],
    ['X-CSRF-Token: ' . $tok, 'Accept: application/json']);
[, $page] = http("$base/product.php?id=$productId");
$tok = token_from($page) ?: $tok;   // refresh from logged-in page
echo "logged in as $buyerEmail (product page http $c)\n";

$madeTxn = 0; $ok = false; $attempts = 0;
try {
    // ── Step 1: create the ONLINE order ─────────────────────────────────
    [$c, $body] = http("$base/product.php?action=make_order&id=$productId",
        ['quantity' => 1, 'payment_method' => 'E-Wallet', 'payment_type' => 'online', '_csrf_token' => $tok],
        ['X-CSRF-Token: ' . $tok]);
    $j = json_decode($body, true);
    check("online order created (200, success)", $c === 200 && ($j['success'] ?? false), $pass, $fail, substr($body, 0, 90));
    if (preg_match('/txn=(\d+)/', $j['redirect'] ?? '', $m)) {
        $madeTxn = (int)$m[1];
    } elseif ($madeTxn === 0) {
        $madeTxn = (int)$pdo->query(
            "SELECT transaction_id FROM transactions WHERE product_id=$productId AND status='Menunggu'
               AND created_at >= NOW() - INTERVAL 2 MINUTE ORDER BY transaction_id DESC LIMIT 1"
        )->fetchColumn();
    }
    if (!$madeTxn) { echo "  could not capture txn id — aborting\n"; }

    // ── Step 2-6: pay + gateway, retry while the random sim declines ────
    // Regression guard: pay.php's form MUST render a hidden _csrf_token so a
    // real browser submit passes csrf_validate() (this once emitted nothing).
    [, $payForm] = http("$base/payment/pay.php?txn=$madeTxn");
    check("pay.php form emits a hidden _csrf_token (native POST can pass CSRF)",
        strpos($payForm, 'name="_csrf_token"') !== false, $pass, $fail);

    while ($madeTxn && $attempts < 5 && !$ok) {
        $attempts++;

        // pay.php GET for token, then POST method=fpx -> 302 to gateway_sim?ref
        [$c, $page] = http("$base/payment/pay.php?txn=$madeTxn");
        $ptok = token_from($page) ?: $tok;
        [$c, , $loc] = http("$base/payment/pay.php?txn=$madeTxn",
            ['payment_method' => 'fpx', '_csrf_token' => $ptok],
            ['X-CSRF-Token: ' . $ptok]);
        $simUrl = $loc && (stripos($loc, 'gateway_sim') !== false)
            ? $loc
            : "$base/payment/gateway_sim.php?ref=" . urlencode((string)$pdo->query(
                "SELECT payment_reference FROM payments WHERE transaction_id=$madeTxn AND status='pending'
                 ORDER BY payment_id DESC LIMIT 1")->fetchColumn());

        // gateway_sim GET for its page token, then POST provider -> 3s sleep -> 302 return.php
        [$c, $simPage] = http($simUrl);
        $stok = token_from($simPage) ?: $ptok;
        [$c, , $retLoc] = http($simUrl,
            ['provider' => 'Maybank2u', '_csrf_token' => $stok],
            ['X-CSRF-Token: ' . $stok]);

        // Follow to return.php and read the rendered verdict.
        $retHtml = '';
        if ($retLoc) {
            [$rc, $retHtml] = http($retLoc);
        }

        // Where did the shared writer land it?
        $pstat = $pdo->prepare("SELECT status FROM payments WHERE transaction_id=? ORDER BY payment_id DESC LIMIT 1");
        $pstat->execute([$madeTxn]); $payStatus = $pstat->fetchColumn();

        if ($payStatus === 'completed') {
            $ok = true;
            check("gateway completed payment on attempt $attempts", true, $pass, $fail);
            check("return.php rendered the SUCCESS page",
                (strpos($retHtml, 'Pembayaran Berjaya') !== false) || (stripos($retLoc ?? '', 'status=success') !== false),
                $pass, $fail);
            break;
        }
        echo "  attempt $attempts: sim declined (payment=$payStatus) — retrying\n";
    }

    // ── W1 assertions on final DB truth ─────────────────────────────────
    if ($ok) {
        $t = $pdo->prepare("SELECT payment_status FROM transactions WHERE transaction_id=?");
        $t->execute([$madeTxn]); $tstat = $t->fetchColumn();
        check("transactions.payment_status = 'paid' (single writer applied it)", $tstat === 'paid', $pass, $fail, "got: " . var_export($tstat, true));

        // Exactly ONE terminal payment for this order — proof there is no double-write
        // creating two completed rows for a single successful checkout.
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE transaction_id=? AND status='completed'");
        $cnt->execute([$madeTxn]); $completed = (int)$cnt->fetchColumn();
        check("exactly one completed payment row", $completed === 1, $pass, $fail, "count: $completed");

        // The audit blob was produced by the shared writer, not the old direct path.
        $gr = $pdo->prepare("SELECT gateway_response FROM payments WHERE transaction_id=? AND status='completed' LIMIT 1");
        $gr->execute([$madeTxn]); $blob = (string)$gr->fetchColumn();
        check("gateway_response carries the simulator audit payload",
            strpos($blob, '"gateway":"simulation"') !== false && strpos($blob, '"result":"approved"') !== false,
            $pass, $fail, substr($blob, 0, 120));
    } else {
        check("checkout reached completed within 5 attempts", false, $pass, $fail, "last attempts: $attempts");
    }
} finally {
    if ($madeTxn) {
        $pdo->prepare("DELETE FROM payments WHERE transaction_id=?")->execute([$madeTxn]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$madeTxn]);
        $pdo->prepare("UPDATE products SET stock=stock+1, sold_count=GREATEST(sold_count-1,0) WHERE product_id=?")->execute([$productId]);
        echo "  cleanup: order $madeTxn + its payments removed, stock restored\n";
    }
}

echo "\n==================================\nRESULT: $pass passed, $fail failed\n==================================\n";
exit($fail === 0 ? 0 : 1);
