<?php
// ============================================================
// scratch/smoke_sweep.php — broad live sweep for latent runtime errors.
// Walks every role's dashboard, admin/seller/buyer actions, public pages,
// auth pages and payment routes as far as they can be reached without
// mutating state. Any response body that contains a PHP diagnostic
// (Warning / Notice / Deprecated / Fatal error / Uncaught / SQLSTATE /
// undefined / Undefined / null has no property...) is flagged.
// Also verifies HTTP status codes look sane (no unexpected 500s).
// CLI only. Non-mutating (GET only; no POSTs).
//   Usage: php scratch/smoke_sweep.php [base-url]
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../tests/uat/lib/uat_lib.php';
uat_set_base($argv[1] ?? null);

$pass = 0; $fail = 0; $warns = [];
function check($label, $cond, $detail = '') {
    global $pass, $fail, $warns;
    if ($cond) { $pass++; }
    else       { $fail++; $warns[] = "$label  -- $detail"; echo "  FAIL  $label  -- $detail\n"; }
}

$DIAG_PATTERNS = [
    'Fatal error', 'Parse error', 'Uncaught', 'Warning:', 'Notice:',
    'Deprecated:', 'SQLSTATE[', 'Undefined index', 'Undefined variable',
    'Undefined array key', 'null has no property', 'TypeError',
    'DivisionByZeroError', 'ValueError'
];
function scan($body) {
    global $DIAG_PATTERNS;
    foreach ($DIAG_PATTERNS as $p) if (stripos($body, $p) !== false) return $p;
    return null;
}

// -- anonymous public surfaces (must render without diagnostic) --------------
$PUBLIC_GET = [
    '/public/index.php',
    '/index.php',
    '/product.php?id=1',
    '/auth/login.php',
    '/auth/register.php',
    '/auth/forgot_password.php',
];
foreach ($PUBLIC_GET as $p) {
    [$c, $b] = uat_req(new UatSession(), $p);
    check("anon $p -> sane HTTP", $c >= 200 && $c < 500, "http=$c");
    $diag = scan($b);
    check("anon $p -> no PHP diagnostic", $diag === null, "found: $diag");
}

// -- per-role sweep --
$ROLES = [
    'pembeli'   => 'aishah@student.uthm.edu.my',
    'penjual'   => 'haziq@student.uthm.edu.my',
    'pentadbir' => 'admin@uthm.edu.my',
];

// Pages each role is allowed to load without error
$PAGES = [
    'pembeli'   => ['/dashboard/buyer.php', '/dashboard/profile.php', '/public/index.php', '/product.php?id=1'],
    'penjual'   => ['/dashboard/seller.php', '/dashboard/buyer.php', '/dashboard/profile.php', '/public/index.php'],
    'pentadbir' => ['/dashboard/admin.php', '/dashboard/profile.php', '/dashboard/admin_returns.php', '/public/index.php'],
];

// JSON actions each role is allowed to call (GET reads only — no POST)
$ACTIONS = [
    'pembeli'   => [
        '/dashboard/buyer.php?action=get_orders',
        '/dashboard/buyer.php?action=get_products',
    ],
    'penjual'   => [
        '/dashboard/seller.php?action=get_products',
        '/dashboard/seller.php?action=get_orders',
        '/dashboard/seller.php?action=get_stats',
    ],
    'pentadbir' => [
        '/dashboard/admin.php?action=get_sellers',
        '/dashboard/admin.php?action=get_disputes',
        '/dashboard/admin.php?action=get_users',
        '/dashboard/admin.php?action=get_transactions',
        '/dashboard/admin.php?action=get_refunds',
        '/dashboard/admin.php?action=get_stats',
        '/dashboard/admin.php?action=get_analytics',
        '/dashboard/admin.php?action=get_ai_risks',
        '/dashboard/admin_returns.php?action=get_pending_returns',
    ],
];

foreach ($ROLES as $role => $email) {
    echo "\n== sweep: $role ($email) ==\n";
    $s = uat_login($email);
    foreach ($PAGES[$role] as $p) {
        [$c, $b] = uat_req($s, $p);
        check("$role page $p -> HTTP 200", $c === 200, "http=$c");
        $diag = scan($b);
        check("$role page $p -> no PHP diagnostic", $diag === null, "found: $diag  body-hunk=" . substr($b, 0, 180));
    }
    foreach ($ACTIONS[$role] as $a) {
        [$c, $b] = uat_req($s, $a, ['json' => true]);
        check("$role action $a -> HTTP 200", $c === 200, "http=$c body=" . substr($b, 0, 200));
        $diag = scan($b);
        check("$role action $a -> no PHP diagnostic", $diag === null, "found: $diag");
        $j = json_decode($b, true);
        check("$role action $a -> success=true", is_array($j) && ($j['success'] ?? false) === true, substr($b, 0, 180));
    }
}

echo "\n==== SMOKE SWEEP RESULT: $pass PASS / $fail FAIL ====\n";
if ($fail) { echo "\nDetails:\n"; foreach ($warns as $w) echo "  - $w\n"; }
exit($fail === 0 ? 0 : 1);
