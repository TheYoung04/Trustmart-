<?php
/**
 * scratch/verify_cash_ui.php — read-only render check of the cash-receipt UI.
 *
 * test_cash_payment_regression.php proves the wiring on an order it crafts and
 * destroys. This one proves it on the REAL stranded demo orders the presenter
 * actually sees (Siti Hajar #208, Mirza #154) and, crucially, asserts the page
 * contains no PHP warning/notice/fatal text — a rendered button is not enough
 * if the dashboard is also leaking an error into the HTML.
 *
 * Does NOT click anything and does NOT mutate any row.
 *
 * Run: C:\xampp\php\php.exe scratch/verify_cash_ui.php
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

$jar = sys_get_temp_dir() . '/tm_cashui_' . getmypid() . '.txt'; @unlink($jar);
function http($url, $post = null) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 20]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $b = curl_exec($ch);
    if ($b === false) { $b = ''; }
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, $b];
}
function token_from($html) {
    // Same two sources the proven harness reads: the meta tag on a public page
    // is the one that actually carries a usable token.
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m)) return $m[1];
    if (preg_match('/name="_csrf_token" value="([^"]+)"/', $html, $m)) return $m[1];
    return '';
}
function jdecode_helper($b) { $j = json_decode($b, true); return is_array($j) ? $j : []; }

// PHP diagnostics land in the HTML only when display_errors is on; catch any of
// them plus the JSON error shell the app emits on an uncaught exception.
function leak_scan($html) {
    $hits = [];
    foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error:', 'Parse error:',
              'Uncaught', 'SQLSTATE[', 'undefined variable', 'Undefined array key'] as $needle) {
        if (stripos($html, $needle) !== false) $hits[] = $needle;
    }
    return $hits;
}

echo "Cash-receipt UI render check — target: $base\n\n";

$targets = $pdo->query("
    SELECT t.transaction_id txn, t.total_amount, u.email seller, u.full_name penjual,
           p.name produk, b.full_name pembeli
      FROM transactions t
      JOIN products p ON p.product_id = t.product_id
      JOIN users u    ON u.user_id    = t.seller_id
      JOIN users b    ON b.user_id    = t.buyer_id
     WHERE t.payment_type = 'cash' AND t.payment_status = 'unpaid_cash'
       AND t.status = 'Menunggu'
  ORDER BY t.transaction_id")->fetchAll(PDO::FETCH_ASSOC);

if (!$targets) {
    echo "  [INFO] No stranded cash orders right now — nothing to render-check.\n";
    echo "         (This check is only meaningful while an unpaid_cash order exists.)\n";
    exit(0);
}

$bySeller = [];
foreach ($targets as $t) { $bySeller[$t['seller']][] = $t; }

foreach ($bySeller as $email => $orders) {
    echo "Seller $email (" . $orders[0]['penjual'] . ")\n";
    @unlink($jar);
    [$c, $b] = http("$base/index.php");
    $tok = token_from($b);
    check('  login page reachable + csrf token', $c === 200 && $tok !== '', "http=$c");
    [$c, $b] = http("$base/auth/login.php", ['email' => $email, 'password' => 'password123', '_csrf_token' => $tok]);
    check('  logged in', $c === 200 && !empty(jdecode_helper($b)['success']), "http=$c");

    [$c, $page] = http("$base/dashboard/seller.php");
    check('  dashboard HTTP 200', $c === 200, "http=$c");
    // A 200 alone proves nothing: an unauthenticated hit 302s to the login page
    // and FOLLOWLOCATION turns that into a 200 full of login markup. Assert the
    // body really is the seller dashboard before trusting every check below it.
    check('  body is genuinely the seller dashboard (not a login redirect)',
        str_contains($page, 'id="tab-produk"'),
        'dashboard tab marker missing — session did not persist');

    $leaks = leak_scan($page);
    check('  no PHP warning/notice/fatal leaked into the page', !$leaks, implode(', ', $leaks));

    foreach ($orders as $o) {
        $txn = (int) $o['txn'];
        check("  #$txn renders \"Sah Terima Tunai\" ({$o['produk']})",
            str_contains($page, "openCashModal($txn") && str_contains($page, 'Sah Terima Tunai'));
        check("  #$txn amount pre-filled at RM" . number_format($o['total_amount'], 2, '.', ''),
            // Whitespace-tolerant: the template emits a space after the comma, and
            // a brittle literal here would fail on a harmless reformat.
            (bool) preg_match('/openCashModal\(\s*' . $txn . '\s*,\s*\'' .
                preg_quote(number_format($o['total_amount'], 2, '.', ''), '/') . '\'\s*\)/', $page));
        check("  #$txn \"Sahkan\" is disabled (server would 422 it)",
            // Deliberately no loose fallback: an unanchored `.*?disabled` would
            // match a disabled control anywhere later in the page and pass falsely.
            (bool) preg_match('/disabled\s+title="Sahkan penerimaan tunai[^"]*">\s*Sahkan/s', $page));
    }

    // The modal must exist exactly once — duplicate ids would make getElementById
    // bind to the wrong node and the submit would target the first order only.
    check('  exactly one #modal-cash node (no duplicate id)',
        substr_count($page, 'id="modal-cash"') === 1, 'found ' . substr_count($page, 'id="modal-cash"'));
    foreach (['openCashModal', 'closeCashModal', 'submitCashConfirm'] as $fn) {
        check("  JS handler $fn is defined", str_contains($page, "function $fn("));
    }
    foreach (['cash-amount', 'cash-method', 'cash-notes', 'cash-submit-btn', 'cash-sub'] as $id) {
        check("  modal field #$id present (JS reads it)", str_contains($page, "id=\"$id\""));
    }
    echo "\n";
}

echo "==============================\n";
echo "PASS: $pass  FAIL: $fail\n";
@unlink($jar);
exit($fail === 0 ? 0 : 1);
