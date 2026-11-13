<?php
// ============================================================
// scratch/edge_case_sweep.php — negative-input / edge-case live sweep.
// Probes surfaces likely to break on weird input:
//   - product.php / receipt.php / pay.php / return.php with 0, -1,
//     huge, non-existent, and non-numeric IDs
//   - auth pages POSTed with malformed data
//   - admin/seller/buyer JSON endpoints with invalid IDs (should
//     422/400/404, never 500)
//   - chat_api.php anonymous + authenticated edge calls
//   - callback.php with missing/invalid signature
//   - gateway_sim.php with tampered params
// None of these mutate DB state (or, if they do, self-clean).
// CLI only.
//   Usage: php scratch/edge_case_sweep.php [base-url]
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../tests/uat/lib/uat_lib.php';
uat_set_base($argv[1] ?? null);

$pass = 0; $fail = 0; $warns = [];
function check($label, $cond, $detail = '') {
    global $pass, $fail, $warns;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else       { $fail++; $warns[] = "$label  -- $detail"; echo "  FAIL  $label  -- $detail\n"; }
}

$DIAG_PATTERNS = [
    'Fatal error', 'Parse error', 'Uncaught', 'Warning:', 'Notice:',
    'Deprecated:', 'SQLSTATE[', 'Undefined index', 'Undefined variable',
    'Undefined array key', 'TypeError', 'ValueError', 'DivisionByZeroError'
];
function hasDiag($body) {
    global $DIAG_PATTERNS;
    foreach ($DIAG_PATTERNS as $p) if (stripos($body, $p) !== false) return $p;
    return null;
}

$anon = new UatSession();

// ── 1. GET edge cases on public pages ────────────────────────
echo "\n== 1. GET edge cases (anonymous) ==\n";
$GET_EDGE = [
    '/product.php?id=0',
    '/product.php?id=-1',
    '/product.php?id=999999999',
    '/product.php?id=abc',
    '/product.php?id=',
    '/product.php',
    '/receipt.php?txn=0',
    '/receipt.php?txn=999999999',
    '/receipt.php?txn=abc',
    '/receipt.php',
    '/payment/pay.php?id=0',
    '/payment/pay.php?id=999999999',
    '/payment/pay.php',
    '/payment/return.php?refno=nope&status_id=1',
    '/payment/return.php',
    '/payment/gateway_sim.php',
    '/chat_api.php',
    '/chat_api.php?action=get_contacts',
    '/chat_api.php?action=get_unread_count',
    '/chat_api.php?action=nonexistent',
    '/index.php?page=999999',
];
foreach ($GET_EDGE as $p) {
    [$c, $b] = uat_req($anon, $p);
    // Expected: not 500, not PHP diagnostic
    check("anon GET $p -> no 5xx", $c < 500, "http=$c");
    $d = hasDiag($b);
    check("anon GET $p -> no PHP diagnostic", $d === null, "found: $d body=" . substr($b, 0, 200));
}

// ── 2. Login edge cases (POST) ───────────────────────────────
echo "\n== 2. Login/register malformed input ==\n";
// grab CSRF first
$s = new UatSession();
uat_token($s, '/auth/login.php');
$tok = $s->token;
$LOGIN_POST = [
    'empty creds'        => ['email'=>'','password'=>'','_csrf_token'=>$tok],
    'wrong password'     => ['email'=>'aishah@student.uthm.edu.my','password'=>'WRONG','_csrf_token'=>$tok],
    'unknown email'      => ['email'=>'ghost@nowhere.com','password'=>'anything','_csrf_token'=>$tok],
    'sql-injection-ish'  => ['email'=>"' OR 1=1 --","password'=>'x",'_csrf_token'=>$tok],
    'oversized email'    => ['email'=>str_repeat('a',500).'@x.com','password'=>'x','_csrf_token'=>$tok],
];
foreach ($LOGIN_POST as $label => $post) {
    [$c, $b] = uat_req($s, '/auth/login.php', ['post'=>$post]);
    check("login $label -> no 5xx", $c < 500, "http=$c");
    $d = hasDiag($b);
    check("login $label -> no PHP diagnostic", $d === null, "found: $d");
}

// ── 3. Authenticated role pages with invalid action params ───
echo "\n== 3. Auth JSON endpoints with bad params ==\n";
foreach (['pentadbir'=>'admin@uthm.edu.my','pembeli'=>'aishah@student.uthm.edu.my','penjual'=>'haziq@student.uthm.edu.my'] as $role=>$email) {
    $s = uat_login($email);
    $BAD = [];
    if ($role === 'pentadbir') {
        $BAD = [
            '/dashboard/admin.php?action=resolve_dispute&id=0&status=resolved',
            '/dashboard/admin.php?action=verify_seller&user_id=0',
            '/dashboard/admin.php?action=verify_seller&user_id=999999',
            '/dashboard/admin.php?action=retry_refund&payment_id=0',
            '/dashboard/admin.php?action=mark_refund_completed&payment_id=0',
            '/dashboard/admin.php?action=unknown_action',
            '/dashboard/admin.php?action=',
            '/dashboard/admin_returns.php?action=update_return_status&return_id=0&status=received',
            '/dashboard/admin_returns.php?action=waive_return&return_id=0&reason=x',
        ];
    } elseif ($role === 'pembeli') {
        $BAD = [
            '/dashboard/buyer.php?action=cancel_order&transaction_id=0',
            '/dashboard/buyer.php?action=cancel_order&transaction_id=999999',
            '/dashboard/buyer.php?action=confirm_received&transaction_id=0',
            '/dashboard/buyer.php?action=submit_review&transaction_id=0&rating=5',
            '/dashboard/buyer.php?action=submit_review&transaction_id=999999&rating=99',
            '/dashboard/buyer.php?action=submit_dispute&transaction_id=0&title=x',
            '/dashboard/buyer.php?action=unknown',
        ];
    } else {
        $BAD = [
            '/dashboard/seller.php?action=update_order_status&transaction_id=0&status=Disahkan',
            '/dashboard/seller.php?action=update_order_status&transaction_id=999999&status=Bogus',
            '/dashboard/seller.php?action=delete_product&product_id=0',
            '/dashboard/seller.php?action=unknown',
        ];
    }
    foreach ($BAD as $p) {
        [$c, $b] = uat_req($s, $p, ['json'=>true]);
        check("$role bad-param $p -> no 5xx", $c < 500, "http=$c body=" . substr($b,0,180));
        $d = hasDiag($b);
        check("$role bad-param $p -> no PHP diagnostic", $d === null, "found: $d");
    }
}

// ── 4. Chat API authenticated edge cases ─────────────────────
echo "\n== 4. chat_api.php authenticated edges ==\n";
$sBuy = uat_login('aishah@student.uthm.edu.my');
$CHAT = [
    '/chat_api.php?action=get_messages&contact_id=0',
    '/chat_api.php?action=get_messages&contact_id=999999',
    '/chat_api.php?action=get_contact_info&user_id=0',
    '/chat_api.php?action=get_contact_info&user_id=999999',
    '/chat_api.php?action=sync',
    '/chat_api.php?action=get_unread_count',
    '/chat_api.php?action=get_contacts',
];
foreach ($CHAT as $p) {
    [$c, $b] = uat_req($sBuy, $p, ['json'=>true]);
    check("buyer chat $p -> no 5xx", $c < 500, "http=$c");
    $d = hasDiag($b);
    check("buyer chat $p -> no PHP diagnostic", $d === null, "found: $d body=" . substr($b,0,180));
}

// ── 5. Callback gateway security ─────────────────────────────
echo "\n== 5. payment/callback.php security probes ==\n";
// Missing params entirely
[$c, $b] = uat_req($anon, '/payment/callback.php', ['post'=>[]]);
check("callback empty POST -> 400 (not 5xx)", $c === 400, "http=$c body=" . substr($b,0,150));
// Bogus refno
[$c, $b] = uat_req($anon, '/payment/callback.php', ['post'=>[
    'refno'=>'DOES_NOT_EXIST','status_id'=>'1','signature'=>''
]]);
check("callback bogus refno -> 4xx (not 5xx)", $c >= 400 && $c < 500, "http=$c");
// SQL-ish refno (regex should reject)
[$c, $b] = uat_req($anon, '/payment/callback.php', ['post'=>[
    'refno'=>'\' OR 1=1 --','status_id'=>'1'
]]);
check("callback SQLi refno -> 4xx (not 5xx)", $c >= 400 && $c < 500, "http=$c");
$d = hasDiag($b);
check("callback SQLi refno -> no PHP diagnostic", $d === null, "found: $d");

echo "\n==== EDGE-CASE SWEEP RESULT: $pass PASS / $fail FAIL ====\n";
if ($fail) { echo "\nDetails:\n"; foreach ($warns as $w) echo "  - $w\n"; }
exit($fail === 0 ? 0 : 1);
