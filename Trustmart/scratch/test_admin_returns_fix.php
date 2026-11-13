<?php
// ============================================================
// scratch/test_admin_returns_fix.php
// Regression guard for DEFECT-001 (admin_returns.php SQL + orphan).
//   - Verifies get_pending_returns returns 200 success JSON (SQL fixed).
//   - Verifies a crafted return_shipments row appears with all joined
//     fields populated (proves dispute_reason maps to the real disputes column).
//   - Verifies admin.php sidebar links to admin_returns.php (nav entry added).
// Self-cleaning: the crafted row is deleted at teardown.
//   Usage: php scratch/test_admin_returns_fix.php [base-url]
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../tests/uat/lib/uat_lib.php';
uat_set_base($argv[1] ?? null);

$BASE = uat_base();
$db   = uat_db();
$pass = 0; $fail = 0;

function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else       { $fail++; echo "  FAIL  $label  -- $detail\n"; }
}

echo "== DEFECT-001 fix regression: admin_returns.php ==\n";

// -- 1. Static code checks --
$src = file_get_contents(__DIR__ . '/../dashboard/admin_returns.php');
check('no d.reason reference remains in get_pending_returns',
    stripos($src, 'd.reason') === false, 'still found d.reason');
check('get_pending_returns now selects d.title AS dispute_reason',
    (bool)preg_match('/d\.title\s+AS\s+dispute_reason/i', $src));
check('admin_returns.php includes shared header.php (real UI, not stub)',
    str_contains($src, "includes/header.php"));
check('admin_returns.php renders a returns-table-body',
    str_contains($src, 'id="returns-table-body"'));

$admin = file_get_contents(__DIR__ . '/../dashboard/admin.php');
check('admin.php sidebar links to admin_returns.php (nav entry)',
    (bool)preg_match('#href=["\']admin_returns\.php["\']#', $admin));

// -- 2. Live auth + endpoint --
$a = uat_login('admin@uthm.edu.my');
check('admin can login', $a->role === 'pentadbir', "role={$a->role}");

// -- 3. Craft one return_shipments row (self-cleaning) --
$pair = $db->query("SELECT d.dispute_id, d.transaction_id FROM disputes d JOIN transactions t ON t.transaction_id=d.transaction_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$pair) { echo "!! No dispute+transaction pair to anchor test — skipping live data assertions.\n"; }
else {
    $db->exec("DELETE FROM return_shipments WHERE notes='__uat_probe__'");
    $ins = $db->prepare("INSERT INTO return_shipments (dispute_id, transaction_id, tracking_number, carrier, status, notes) VALUES (?,?,?,?,?,?)");
    $ins->execute([(int)$pair['dispute_id'], (int)$pair['transaction_id'], 'MY-UAT-TRACK-001', 'J&T', 'pending', '__uat_probe__']);
    $madeId = (int)$db->lastInsertId();
    uat_on_cleanup(function () use ($db, $madeId) {
        $db->prepare("DELETE FROM return_shipments WHERE return_id=?")->execute([$madeId]);
    });
    check('return_shipments fixture created', $madeId > 0, "id=$madeId");

    // -- 4. Endpoint must return 200 + success + at least the fixture row --
    [$c, $body, $j] = uat_req($a, '/dashboard/admin_returns.php?action=get_pending_returns', ['json' => true]);
    check('get_pending_returns -> HTTP 200', $c === 200, "http=$c body=" . substr($body, 0, 200));
    check('get_pending_returns -> success=true (was false/500 before fix)', ($j['success'] ?? false) === true, $body);
    $rows = $j['returns'] ?? [];
    $mine = null; foreach ($rows as $r) if ((int)($r['return_id'] ?? 0) === $madeId) { $mine = $r; break; }
    check('crafted return row appears in list', is_array($mine), 'count=' . count($rows));
    if (is_array($mine)) {
        check('dispute_reason is populated (d.title resolves)',
            isset($mine['dispute_reason']) && $mine['dispute_reason'] !== '',
            json_encode($mine['dispute_reason'] ?? null));
        check('tracking_number round-trips', ($mine['tracking_number'] ?? '') === 'MY-UAT-TRACK-001', json_encode($mine['tracking_number'] ?? null));
        check('buyer/seller/product names present',
            !empty($mine['buyer_name']) && !empty($mine['seller_name']) && !empty($mine['product_name']),
            json_encode([$mine['buyer_name'] ?? null, $mine['seller_name'] ?? null, $mine['product_name'] ?? null]));
    }

    // -- 5. The outer page must render (was 200-stub before, still 200 with real UI now) --
    [$c2, $b2] = uat_req($a, '/dashboard/admin_returns.php');
    check('admin_returns.php outer page -> 200', $c2 === 200, "http=$c2");
    check('outer page HTML contains the returns table body element', str_contains($b2, 'returns-table-body'));

    // -- 5b. Shared CSS/JS must resolve from /dashboard/ context --
    // Regression guard for header.php's earlier bug where "public/assets/..."
    // was emitted relative (only worked from app root), causing 404 + MIME
    // errors on any /dashboard/*.php or /payment/*.php that includes it.
    if (preg_match('#<link rel="stylesheet" href="([^"]*trustmart-ui\.css)">#', $b2, $m)) {
        $cssHref = $m[1];
        check('dashboard/admin_returns.php uses ../ prefix for shared CSS',
            strpos($cssHref, '../public/assets/') === 0, "href=$cssHref");
        // resolve relative to /dashboard/ → /public/assets/…
        $resolved = '/public/assets/css/trustmart-ui.css';
        $r = curl_init($BASE . $resolved);
        curl_setopt_array($r, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>8]);
        $cssBody = curl_exec($r); $cssType = curl_getinfo($r, CURLINFO_CONTENT_TYPE); curl_close($r);
        check('shared trustmart-ui.css reachable at resolved URL',
            $cssType && stripos($cssType, 'text/css') !== false, "type=$cssType");
    } else {
        check('stylesheet link is emitted on admin_returns.php', false, 'no <link> tag found in body');
    }

    // -- 6. Negative: anonymous call still refuses --
    $anon = new UatSession();
    [$c3] = uat_req($anon, '/dashboard/admin_returns.php?action=get_pending_returns', ['json' => true]);
    check('anonymous get_pending_returns -> 401', $c3 === 401, "http=$c3");
}

echo "\n==== DEFECT-001 FIX RESULT: $pass PASS / $fail FAIL ====\n";
exit($fail === 0 ? 0 : 1);
