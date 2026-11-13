<?php
// ============================================================
// test_trust_unify_paysync.php
// Covers the two fixes requested after the live E2E run:
//   A1/A10 — trust source unified on the role-specific Bayesian
//            trust_profiles row with the "Baharu" convention across
//            catalogue cards, product page and chat header (no more
//            legacy users.trust_score 0/100 vs "Baharu" mismatch).
//   A3     — an ONLINE order can no longer carry the cash 'Tunai'
//            payment method (client hides/syncs the picker, and the
//            server coerces Tunai -> E-Wallet as the real guard).
// Usage: php test_trust_unify_paysync.php [base_url]
// ============================================================
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/includes/trust_score.php';
require_once $root . '/includes/ai_trust.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail, $d = '') {
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}

echo "-- unit: trust_display_from_row (single source of truth) --\n";
$b = trust_display_from_row(['trust_evidence' => 0, 'trust_baseline' => 40, 'ai_trust_score' => null]);
check('no evidence  -> "Baharu" (grey, not 0)', $b === ['label' => 'Baharu', 'color' => 'var(--ink4)', 'has_score' => false], $pass, $fail, json_encode($b));
$b2 = trust_display_from_row(['trust_evidence' => 5, 'trust_baseline' => 66, 'ai_trust_score' => null]);
check('evidence, AI pending -> baseline score', $b2['label'] === '66' && $b2['has_score'], $pass, $fail, json_encode($b2));
$b3 = trust_display_from_row(['trust_evidence' => 5, 'trust_baseline' => 66, 'ai_trust_score' => 42]);
check('evidence + AI -> AI-adjusted score', $b3['label'] === '42', $pass, $fail, json_encode($b3));
$b4 = trust_display_from_row(['trust_evidence' => 0, 'ai_trust_score' => 0]);
check('no evidence + ai 0 -> still "Baharu" (the exact card/chat bug)', $b4['label'] === 'Baharu', $pass, $fail, json_encode($b4));

echo "\n-- static: buyer.php reads trust_profiles, not the legacy mirror --\n";
$buyer = file_get_contents($root . '/dashboard/buyer.php');
check('card queries select trust_baseline + trust_evidence',
    substr_count($buyer, 'tp.score AS trust_baseline, tp.evidence_units AS trust_evidence') === 2, $pass, $fail);
check('card queries NO LONGER select legacy u.trust_score',
    !preg_match('/u\.trust_score/', $buyer), $pass, $fail);
// The AI-coalesced ordering belongs to the 'Skor Tertinggi' branch of the sort
// map. The server-rendered first page deliberately follows the dropdown default
// ('terbaharu') so the grid agrees with its own controls (see
// test_catalogue_variety.php section 5), so assert the branch itself rather than
// a raw occurrence count — that count dropped from 2 to 1 when the featured
// query stopped pre-sorting by trust, which was the intended change.
check("sort map orders trust-desc from the AI-adjusted profile",
    strpos($buyer, "'trust-desc'  => 'COALESCE(tp.ai_trust_score, tp.score, 0) DESC'") !== false, $pass, $fail);
check('no ORDER BY falls back to the legacy users.trust_score mirror',
    !preg_match('/ORDER BY[^;{]*u\.trust_score/', $buyer), $pass, $fail);
check('JS grid renders the server trust_display', strpos($buyer, 'p.trust_display') !== false, $pass, $fail);
check('chat list carries data-trust-display attribute', strpos($buyer, 'data-trust-display') !== false, $pass, $fail);
check('chat header renders trustDisplay + /100 only when has_score',
    strpos($buyer, '${hasScore ? \'/100\' : \'\'}') !== false, $pass, $fail);

echo "\n-- static: product.php payment method is server-derived (A3 v2) --\n";
$prod = file_get_contents($root . '/product.php');
check('server derives payment_method from payment_type',
    strpos($prod, "\$payment_method = (\$payment_type === 'online') ? 'E-Wallet' : 'Tunai';") !== false, $pass, $fail);
check('redundant #payment-method dropdown removed',
    strpos($prod, 'id="payment-method"') === false, $pass, $fail);
check('syncPaymentMethod() removed (single picker now)',
    strpos($prod, 'syncPaymentMethod') === false, $pass, $fail);

echo "\n-- live: get_products rows expose the unified label --\n";
$jar = sys_get_temp_dir() . '/tm_unify.txt'; @unlink($jar);
function http($url, $post = null, $hdr = []) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20,
    ]);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$body];
}
function tok($body) { return preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m) ? $m[1] : ''; }

[, $page] = http("$base/public/");
$t = tok($page);
[$c, $login] = http("$base/auth/login.php",
    ['email' => 'aishah@student.uthm.edu.my', 'password' => 'password123', '_csrf_token' => $t],
    ['X-CSRF-Token: ' . $t, 'Accept: application/json']);
check('buyer login ok', strpos($login, '"success":true') !== false, $pass, $fail, "http $c");

[$c, $body] = http("$base/dashboard/buyer.php?action=get_products&q=");
$data = json_decode($body, true);
$prods = $data['products'] ?? [];
check('get_products returns rows with trust_display',
    is_array($data) && ($data['success'] ?? false) && $prods && array_key_exists('trust_display', $prods[0]), $pass, $fail, "http $c");
check('get_products no longer leaks legacy trust_score key',
    $prods && !array_key_exists('trust_score', $prods[0]), $pass, $fail);
// A Baharu seller must read "Baharu" on the card, matching the chat header.
$baharu = null;
foreach ($prods as $p) { if ((float)($p['trust_evidence'] ?? 0) <= 0) { $baharu = $p; break; } }
if ($baharu) {
    check('zero-evidence seller card shows "Baharu" (unified)', ($baharu['trust_display'] === 'Baharu'), $pass, $fail, $baharu['trust_display']);
} else {
    check('(no zero-evidence seller in current catalogue to compare)', true, $pass, $fail);
}

echo "\n-- live: ONLINE order rejects cash 'Tunai' method (A3 server guard) --\n";
$prodRow = $pdo->query(
    "SELECT p.product_id, p.seller_id, p.stock FROM products p
       JOIN users u ON u.user_id = p.seller_id
       LEFT JOIN trust_profiles tp ON tp.user_id = p.seller_id AND tp.profile_role='penjual'
      WHERE p.status='Aktif' AND p.stock > 0 AND u.is_verified=1 AND u.is_active=1
        AND u.user_id <> (SELECT user_id FROM users WHERE email='aishah@student.uthm.edu.my')
        AND (tp.ai_trust_score IS NULL OR tp.fraud_risk < " . (int) AI_TRUST_HOLD_RISK . " OR tp.ai_hold = 0)
      ORDER BY p.product_id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (!$prodRow) {
    check('(no buyable product for the online-method test)', true, $pass, $fail);
} else {
    $pid = (int)$prodRow['product_id'];
    // Re-fetch fresh token for the logged-in session.
    [, $pp] = http("$base/product.php?id=$pid");
    $t = tok($pp) ?: $t;
    // Deliberately post the contradictory combo: online order + cash method.
    [$c, $body] = http("$base/product.php?action=make_order&id=$pid",
        ['quantity' => 1, 'payment_method' => 'Tunai', 'payment_type' => 'online', '_csrf_token' => $t],
        ['X-CSRF-Token: ' . $t]);
    $j = json_decode($body, true);
    check('online order created despite Tunai method (200)', $c === 200 && ($j['success'] ?? false), $pass, $fail, substr($body, 0, 90));
    $txn = (int)$pdo->query(
        "SELECT transaction_id FROM transactions WHERE product_id=$pid AND status='Menunggu'
           AND created_at >= NOW() - INTERVAL 2 MINUTE ORDER BY transaction_id DESC LIMIT 1"
    )->fetchColumn();
    if ($txn) {
        $stored = $pdo->query("SELECT payment_type, payment_method FROM transactions WHERE transaction_id=$txn")->fetch(PDO::FETCH_ASSOC);
        check('stored payment_type is online', ($stored['payment_type'] ?? '') === 'online', $pass, $fail, json_encode($stored));
        check('stored payment_method was COERCED away from Tunai', ($stored['payment_method'] ?? '') !== 'Tunai', $pass, $fail, $stored['payment_method'] ?? '?');
        check('coerced method is E-Wallet', ($stored['payment_method'] ?? '') === 'E-Wallet', $pass, $fail, $stored['payment_method'] ?? '?');
        // Cleanup: remove order + any payments, restore stock/sold_count.
        $pdo->exec("DELETE FROM payments WHERE transaction_id=$txn");
        $pdo->exec("DELETE FROM transactions WHERE transaction_id=$txn");
        $pdo->prepare("UPDATE products SET stock = stock + 1, sold_count = GREATEST(sold_count - 1, 0) WHERE product_id=$pid")->execute();
        echo "  cleanup: online test txn $txn removed, stock restored\n";
    } else {
        check('captured the online test transaction id', false, $pass, $fail);
    }
}

echo "\nRESULT: $pass passed, $fail failed\n";
@unlink($jar);
exit($fail === 0 ? 0 : 1);
