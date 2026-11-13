<?php
/**
 * scratch/verify_dispute_modal_ui.php — read-only render check of the admin
 * dispute-resolution modal.
 *
 * Why this exists: the dashboard quick-view ("Aduan Memerlukan Perhatian") calls
 * openDisputeModal(dispute_id, transaction_id, total) but for a while passed only
 * the dispute_id, because the feeding query never SELECTed t.transaction_id. The
 * modal then rendered "Pesanan #undefined" and prefilled the refund with 0.00.
 * That is not cosmetic: resolve_dispute clamps the refund to
 * min(order_total, submitted), so submitting the prefilled 0.00 records a RM0.00
 * refund against a real order. No endpoint-level test could see it — the payload
 * was valid, just wrong — so the guard has to read the rendered HTML.
 *
 * Does NOT click anything and does NOT mutate any row.
 *
 * Run: C:\xampp\php\php.exe scratch/verify_dispute_modal_ui.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only.\n"); }

$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, $d = '') {
    global $pass, $fail;
    // The detail is a failure diagnosis; printing it on a PASS line reads as if
    // something was wrong (e.g. "markers missing" next to a passing check).
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . (!$c && $d !== '' ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}

$jar = sys_get_temp_dir() . '/tm_dispui_' . getmypid() . '.txt'; @unlink($jar);
function http($url, $post = null) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
        // index.php is the root router and 302s to the storefront, so the csrf
        // meta tag is only reachable by following it. The redirect can also mask
        // an unauthenticated dashboard hit, which is why every fetch below is
        // additionally pinned by a positive page-marker assertion.
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
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m)) return $m[1];
    if (preg_match('/name="_csrf_token" value="([^"]+)"/', $html, $m)) return $m[1];
    return '';
}
function jdec($b) { $j = json_decode($b, true); return is_array($j) ? $j : []; }
function leak_scan($html) {
    $hits = [];
    foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error:', 'Parse error:',
              'Uncaught', 'SQLSTATE[', 'Undefined array key', 'undefined variable'] as $needle) {
        if (stripos($html, $needle) !== false) $hits[] = $needle;
    }
    return $hits;
}

echo "Dispute-modal UI render check — target: $base\n\n";

// The same open disputes the quick-view is supposed to list: severity then age,
// top 5. Read straight from the DB so the assertions below cannot pass on
// numbers the page invented.
$rows = $pdo->query("SELECT d.dispute_id, t.transaction_id, t.total_amount
      FROM disputes d JOIN transactions t ON t.transaction_id = d.transaction_id
     WHERE d.status != 'Selesai'
     ORDER BY FIELD(d.severity,'Mendesak','Sederhana','Rendah'), d.created_at
     LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "  [INFO] No open disputes — nothing to render-check.\n";
    echo "         (Arm one with scratch/seed_demo_scenes.php; this guard only means\n";
    echo "          something while the quick-view has a card to draw.)\n";
    exit(0);
}

$email = $pdo->query("SELECT email FROM users WHERE role='pentadbir' AND is_active=1 LIMIT 1")->fetchColumn();
if (!$email) { echo "  [FAIL] no active admin account to sign in with\n"; exit(1); }
echo "Admin: $email  ·  open disputes in quick-view: " . count($rows) . "\n\n";

[, $page0] = http("$base/index.php");
$tok = token_from($page0);
check('login page reachable + csrf token', $tok !== '');
[$c, $b] = http("$base/auth/login.php", ['email' => $email, 'password' => 'password123', '_csrf_token' => $tok]);
check('logged in', !empty(jdec($b)['success']), 'http=' . $c);

[$c, $page] = http("$base/dashboard/admin.php");
check('dashboard HTTP 200', $c === 200, "http=$c");
// FOLLOWLOCATION would turn a 302-to-login into a misleading 200, so the body
// has to prove it is the admin dashboard before anything below is trusted.
check('body is genuinely the admin dashboard (not a login redirect)',
    str_contains($page, 'id="tab-dash"') && str_contains($page, 'id="modal-dispute"'),
    'admin markers missing — session did not persist');

$leaks = leak_scan($page);
check('no PHP warning/notice/fatal leaked into the page', !$leaks, implode(', ', $leaks));

// ── The quick-view region only: server-rendered onclick= attributes ──────────
$start = strpos($page, 'Aduan Memerlukan Perhatian');
$end   = strpos($page, 'id="tab-pengesahan"');
$region = ($start !== false && $end !== false && $end > $start)
    ? substr($page, $start, $end - $start) : '';
check('quick-view section located in the rendered page', $region !== '');

preg_match_all('/openDisputeModal\(([^)]*)\)/', $region, $m);
$calls = $m[1] ?? [];
check('quick-view rendered one Selesaikan call per open dispute',
    count($calls) === count($rows),
    'rendered=' . count($calls) . ' db=' . count($rows));

foreach ($rows as $r) {
    $id  = (int) $r['dispute_id'];
    $txn = (int) $r['transaction_id'];
    // number_format drops trailing zeros the JS toFixed keeps, so compare the
    // value, not the string: 1850.00 and 1850 are the same ceiling.
    $want = number_format((float) $r['total_amount'], 2, '.', '');
    $hit = null;
    foreach ($calls as $arglist) {
        if (preg_match('/^\s*(\d+)\s*,\s*(\d+)\s*,\s*([\d.]+)\s*$/', $arglist, $g) && (int)$g[1] === $id) {
            $hit = $g;
            break;
        }
    }
    check("  aduan #$id passes all three arguments", $hit !== null,
        $hit === null ? 'call site is: ' . implode(' | ', array_map(fn($s) => "($s)", $calls)) : 'ok');
    if ($hit === null) continue;
    check("  #$id resolves to real order #$txn (not undefined)", (int) $hit[2] === $txn, "got #{$hit[2]}");
    check("  #$id refund ceiling = RM$want (order total)", abs((float)$hit[3] - (float)$want) < 0.005, "got {$hit[3]}");
}

// A one-argument call is the exact regression this guard exists to catch.
// Both checks demand a non-empty region: an empty string satisfies "no match"
// and would report PASS on a page that never rendered the section at all.
$short = array_filter($calls, fn($a) => substr_count($a, ',') < 2);
check('no single-argument openDisputeModal() call left in the quick-view',
    $region !== '' && !$short, implode(' | ', $short));
check('rendered quick-view contains no "undefined" text',
    $region !== '' && stripos($region, 'undefined') === false, 'found in region');

// ── The JS itself: a missing total must never become a silent 0.00 ceiling ───
// Capture up to the brace at column 0, i.e. the whole function body. Stopping at
// the first "\n  }" would truncate inside the if/else and hide the very branch
// these assertions are about.
$fn = '';
if (preg_match('/function openDisputeModal\(.*?\n\}/s', $page, $mm)) $fn = $mm[0];
check('openDisputeModal body captured in full', $fn !== '' && substr_count($fn, 'toggleSettleSection') >= 1);
check('openDisputeModal defined exactly once',
    substr_count($page, 'function openDisputeModal(') === 1,
    'found ' . substr_count($page, 'function openDisputeModal('));
check('JS rejects a non-numeric total instead of coercing it to 0.00',
    $fn !== '' && str_contains($fn, 'isFinite'), 'no isFinite guard in the body');
check('JS hides the "Pesanan #" label when the order id is absent',
    $fn !== '' && str_contains($fn, "transaction_id ?"), 'label is rendered unconditionally');
check('unknown total falls back to a blank field (server bills the full order)',
    $fn !== '' && str_contains($fn, "removeAttribute('max')"), 'no blank-fallback branch');

// The AJAX list is the other caller; it must keep passing three arguments too.
check('AJAX dispute list still passes three arguments',
    (bool) preg_match('/openDisputeModal\(\$\{d\.dispute_id\},\s*\$\{d\.transaction_id\},/', $page));

// The settle inputs sit outside a <form>, so nothing but this branch stops an
// over-max refund amount reaching the server (which clamps it quietly).
$sub = '';
if (preg_match('/async function submitDisputeResolution\(\).*?\n\}/s', $page, $ms)) $sub = $ms[0];
check('submitDisputeResolution refuses an amount above the order total',
    $sub !== '' && str_contains($sub, 'tidak boleh melebihi nilai pesanan'), 'no client-side ceiling check');
check('that rejection restores the button instead of leaving it spinning',
    $sub !== '' && substr_count($sub, "btn.textContent = 'Sahkan Keputusan'") >= 2,
    'early return would strand the disabled button');

// Modal nodes the JS writes into must exist, or getElementById returns null and
// the whole open path throws before the admin sees anything.
foreach (['dispute-modal-id', 'dispute-modal-sub', 'settle-amount', 'settle-max',
          'settle-return', 'settle-fulfilment', 'settle-notes', 'settle-section',
          'modal-dispute', 'dispute-resolution', 'dispute-status'] as $id) {
    check("modal node #$id present", str_contains($page, "id=\"$id\""));
}

echo "\n==============================\n";
echo "PASS: $pass  FAIL: $fail\n";
@unlink($jar);
exit($fail === 0 ? 0 : 1);
