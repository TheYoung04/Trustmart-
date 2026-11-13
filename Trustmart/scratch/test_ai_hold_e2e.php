<?php
/**
 * End-to-end proof of the AI fraud-hold order gate over real HTTP:
 *   1. seller held (fraud_risk>=50, ai_hold=1) → make_order returns 403
 *   2. admin releases hold (ai_hold=0)         → order succeeds (cleaned up)
 * Run: php scratch/test_ai_hold_e2e.php   (needs Apache on localhost)
 */

$base = 'http://localhost/Trustmart';
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail) { echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . "\n"; $c ? $pass++ : $fail++; }
function fetch($url, $post = null, $hdr = []) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar]);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$c, (string)$b];
}

// Find a buyable product from a real seller (not the buyer themself).
$prod = $pdo->query(
    "SELECT p.product_id, p.seller_id, p.stock FROM products p
       JOIN users u ON u.user_id = p.seller_id
      WHERE p.status='Aktif' AND p.stock > 0 AND u.is_verified=1 AND u.is_active=1
        AND EXISTS (SELECT 1 FROM trust_profiles tp WHERE tp.user_id=p.seller_id AND tp.profile_role='penjual')
      LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (!$prod) { echo "No suitable product found\n"; exit(1); }
$sellerId = (int)$prod['seller_id'];
echo "product={$prod['product_id']} seller=$sellerId\n";

$orig = $pdo->query("SELECT fraud_risk, ai_hold FROM trust_profiles WHERE user_id=$sellerId AND profile_role='penjual'")->fetch(PDO::FETCH_ASSOC);

// Login as a buyer who is not the seller.
$b = $pdo->query("SELECT u.email FROM users u WHERE u.role='pembeli' AND u.is_active=1 AND u.user_id <> $sellerId LIMIT 1")->fetchColumn();
$jar = sys_get_temp_dir() . '/tm_hold_e2e.txt'; @unlink($jar);
[$c, $page] = fetch("$base/public/");
preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m); $tok = $m[1] ?? '';
fetch("$base/auth/login.php", ['email'=>$b, 'password'=>'password123', '_csrf_token'=>$tok], ['X-CSRF-Token: '.$tok, 'Accept: application/json']);
[$c, $page] = fetch("$base/product.php?id={$prod['product_id']}"); // logged-in page carries fresh token
preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m); $tok = $m[1] ?? '';
echo "logged in as $b (order page http $c, token " . ($tok ? 'ok' : 'MISSING') . ")\n";

$madeTxn = 0;
try {
    // Case 1: seller held by the AI layer.
    $pdo->exec("UPDATE trust_profiles SET fraud_risk=60, ai_hold=1 WHERE user_id=$sellerId AND profile_role='penjual'");
    [$c, $body] = fetch("$base/product.php?action=make_order&id={$prod['product_id']}",
        ['quantity'=>1, 'payment_method'=>'Tunai', 'payment_type'=>'cash', '_csrf_token'=>$tok],
        ['X-CSRF-Token: '.$tok]);
    $j = json_decode($body, true);
    echo "  blocked response: $c " . substr($body, 0, 120) . "\n";
    check("held seller: order BLOCKED (403)", $c === 403 && !($j['success'] ?? false), $pass, $fail);

    // Case 2: admin releases the hold → same order goes through.
    $pdo->exec("UPDATE trust_profiles SET ai_hold=0 WHERE user_id=$sellerId AND profile_role='penjual'");
    [$c, $body] = fetch("$base/product.php?action=make_order&id={$prod['product_id']}",
        ['quantity'=>1, 'payment_method'=>'Tunai', 'payment_type'=>'cash', '_csrf_token'=>$tok],
        ['X-CSRF-Token: '.$tok]);
    $j = json_decode($body, true);
    echo "  released response: $c " . substr($body, 0, 120) . "\n";
    check("released seller: order SUCCEEDS (200)", $c === 200 && ($j['success'] ?? false), $pass, $fail);
    if (!empty($j['success'])) {
        if (preg_match('/txn=(\d+)/', $j['redirect'] ?? '', $mm)) {
            $madeTxn = (int)$mm[1];
        } else {
            // Cash orders redirect without a txn id — find the order we just made.
            $madeTxn = (int)$pdo->query(
                "SELECT transaction_id FROM transactions
                  WHERE product_id={$prod['product_id']} AND seller_id=$sellerId AND status='Menunggu'
                    AND created_at >= NOW() - INTERVAL 2 MINUTE
                  ORDER BY transaction_id DESC LIMIT 1"
            )->fetchColumn();
        }
    }
} finally {
    // Undo the test order + restore the profile exactly.
    if ($madeTxn) {
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$madeTxn]);
        $pdo->prepare("UPDATE products SET stock=stock+1, sold_count=GREATEST(sold_count-1,0) WHERE product_id=?")->execute([(int)$prod['product_id']]);
        echo "  test order $madeTxn removed\n";
    }
    $pdo->prepare("UPDATE trust_profiles SET fraud_risk=?, ai_hold=? WHERE user_id=? AND profile_role='penjual'")
        ->execute([$orig['fraud_risk'], $orig['ai_hold'], $sellerId]);
    $pdo->prepare("UPDATE users SET trust_score=trust_score WHERE user_id=?")->execute([$sellerId]); // touch only
    echo "  seller profile restored\n";
}

echo "\n==================================\nRESULT: $pass passed, $fail failed\n==================================\n";
exit($fail === 0 ? 0 : 1);
