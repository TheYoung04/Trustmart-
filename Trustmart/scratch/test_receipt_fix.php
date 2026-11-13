<?php
// test_receipt_fix.php
// Verifies the two payment-flow bugs found by the live browser E2E run:
//   1. receipt.php fatal: SELECT referenced users.phone (column does not exist)
//   2. gateway_sim.php stylesheet pointed at /public/trustmart-ui.css (404)
// Runs over HTTP against Apache (http://localhost/Trustmart) with a real buyer session.

$BASE = 'http://localhost/Trustmart';
$JAR  = sys_get_temp_dir() . '/tm_receipt_fix_cookies.txt';

$PASS = 0; $FAIL = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  [PASS] $name\n"; }
    else     { $FAIL++; echo "  [FAIL] $name  ($detail)\n"; }
}

function http(string $url, array $opts = []): array {
    global $JAR;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $JAR,
        CURLOPT_COOKIEFILE     => $JAR,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if (!empty($opts['post'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['post']));
    }
    if (!empty($opts['headers'])) curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body];
}

@unlink($JAR);

// ---- Static checks ---------------------------------------------------------
$src = file_get_contents(__DIR__ . '/../receipt.php');
ck('receipt.php no longer selects users.phone', !preg_match('/\b(buyer|seller)\.phone\b/i', $src));
// Coupling check, not a wording check: the receipt renders whatever alias the
// SELECT produces. The aliases are now type-aware (COALESCE of matric_no and
// staff_no, so a staff buyer/seller is not blank), so this asserts that each key
// the template reads is actually produced by the query.
ck('receipt.php selects the identifier columns the template renders',
   strpos($src, 'AS buyer_ident') !== false
   && strpos($src, "\$transaction['buyer_ident']") !== false
   && strpos($src, 'AS seller_ident') !== false
   && strpos($src, "\$transaction['seller_ident']") !== false);
ck('receipt.php no longer renders a bare matric_no column',
   !preg_match('/\b(buyer|seller)\.matric_no\s+AS\s+(buyer|seller)_matric/', $src));

$lint = shell_exec('C:\xampp\php\php.exe -l "' . __DIR__ . '/../receipt.php" 2>&1');
ck('receipt.php lint clean', str_contains((string)$lint, 'No syntax errors'), trim((string)$lint));

$gs = file_get_contents(__DIR__ . '/../payment/gateway_sim.php');
ck('gateway_sim.php css points at public/assets/css/trustmart-ui.css',
   str_contains($gs, '/public/assets/css/trustmart-ui.css'));

// ---- Live: asset exists ----------------------------------------------------
$css = http($BASE . '/public/assets/css/trustmart-ui.css');
ck('gateway css URL returns 200 text/css', $css['code'] === 200 && str_contains($css['body'], '--'),
   "code={$css['code']}");

// ---- Live: login as buyer --------------------------------------------------
// auth/login.php is a JSON API (GET 405); /public/ serves the landing page
// with the CSRF meta tag — exact landing used by test_live_checkout.
$r = http($BASE . '/public/');
$token = '';
if (preg_match('/name="csrf-token" content="([^"]+)"/', $r['body'], $m)) $token = $m[1];
ck('csrf token obtained from landing page', $token !== '', 'code=' . $r['code']);
$ok = http($BASE . '/auth/login.php', [
    'post'   => ['email' => 'aishah@student.uthm.edu.my', 'password' => 'password123', '_csrf_token' => $token],
    'headers'=> ["X-CSRF-Token: $token"],
]);
ck('buyer login succeeded', $ok['code'] === 302 || stripos($ok['body'], 'dashboard') !== false, 'code=' . $ok['code']);

// ---- Live: fetch receipt for a completed transaction -----------------------
require __DIR__ . '/../config/db.php';
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE email='aishah@student.uthm.edu.my'")->fetchColumn();
// Prefer one of THIS buyer's own finished orders so the full template renders.
$stmt = $pdo->prepare("SELECT transaction_id FROM transactions
                        WHERE buyer_id = ? AND status IN ('Disahkan','Selesai') LIMIT 1");
$stmt->execute([$uid]);
$txn = $stmt->fetchColumn() ?: $pdo->query("SELECT transaction_id FROM transactions WHERE status IN ('Disahkan','Selesai') LIMIT 1")->fetchColumn();

if ($txn) {
    $rec = http($BASE . "/receipt.php?txn=$txn");
    ck("receipt.php?txn=$txn returns 200", $rec['code'] === 200, 'code=' . $rec['code']);
    ck('receipt page has NO PDO fatal',
       !str_contains($rec['body'], 'Fatal error') && !str_contains($rec['body'], 'PDOException'),
       substr(preg_replace('/\s+/', ' ', $rec['body']), 0, 200));
    ck('receipt page renders content', stripos($rec['body'], 'resit') !== false || stripos($rec['body'], 'Receipt') !== false
       || str_contains($rec['body'], 'RM'));
} else {
    // No eligible txn (should not happen on demo data) — at least prove the query works.
    ck('found a Disahkan/Selesai transaction to test', false, 'none in DB');
}

// An order the buyer JUST placed (still Menunggu) must now render a full
// provisional receipt flagged Pending, instead of the old "Resit Belum
// Tersedia" hold. Uses one of THIS buyer's own orders so ownership passes.
$pending = $pdo->prepare("SELECT transaction_id FROM transactions WHERE buyer_id=? AND status='Menunggu' LIMIT 1");
$pending->execute([$uid]);
$pending = $pending->fetchColumn();
if ($pending) {
    $rec2 = http($BASE . "/receipt.php?txn=$pending");
    ck('pending-txn receipt renders the full provisional template, no fatal',
       $rec2['code'] === 200 && !str_contains($rec2['body'], 'Fatal error')
       && str_contains($rec2['body'], 'Maklumat Resit')
       && str_contains($rec2['body'], 'JUMLAH KESELURUHAN'),
       'code=' . $rec2['code']);
    ck('pending-txn receipt is flagged Pending (provisional banner)',
       stripos($rec2['body'], 'Pending') !== false && stripos($rec2['body'], 'Resit Provisional') !== false
       // the old withhold card must be gone for a live order
       && !str_contains($rec2['body'], 'Resit Belum Tersedia'),
       substr(preg_replace('/\s+/', ' ', $rec2['body']), 0, 160));
}

echo "\nRESULT: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
