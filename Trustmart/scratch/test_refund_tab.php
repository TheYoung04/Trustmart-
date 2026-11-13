<?php
// scratch/test_refund_tab.php — verify the admin "Pulangan Wang" refund tab
// end-to-end: (a) the served page now contains the client wiring that was
// missing (nav link + loadRefunds/filterRefunds/renderRefunds + showTab lazy
// branch), and (b) the two pending_return backend fixes in get_refunds
// (L611 allowed_statuses + summary bucket) behave correctly over real HTTP.
//
// Crafts refund states by flipping the single existing refund payment, then
// ALWAYS restores the baseline (row 39 -> refund_status='completed', refund_error NULL).
//
// Usage: php scratch/test_refund_tab.php [base-url]   (default http://localhost:8080)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../config/db.php';

$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$jar  = sys_get_temp_dir() . '/tm_refund_tab.txt'; @unlink($jar);

$PASS = 0; $FAIL = 0;
function check($name, $cond, $detail = '') {
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "  PASS  $name\n"; }
    else       { $FAIL++; echo "  FAIL  $name  -- $detail\n"; }
}

function http($url, $post = null) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>5, CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar, CURLOPT_TIMEOUT=>20]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$c, (string)$b];
}

// ── Snapshot + guaranteed restore of the one refund payment ─────────
$pay = $pdo->query("SELECT payment_id, refund_status, refund_error FROM payments
                    WHERE refund_status <> 'none' ORDER BY payment_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$pay) { exit("No refund payment present to exercise the tab against — aborting without changes.\n"); }
$PID  = (int)$pay['payment_id'];
$BASE = $pay['refund_status'];
$BASE_ERR = $pay['refund_error'];
echo "Target payment #$PID (baseline refund_status='$BASE')\n";

register_shutdown_function(function () use ($pdo, $PID, $BASE, $BASE_ERR) {
    $st = $pdo->prepare("UPDATE payments SET refund_status = ?, refund_error = ? WHERE payment_id = ?");
    $st->execute([$BASE, $BASE_ERR, $PID]);
    @unlink($jar);
    echo "\n[teardown] payment #$PID restored to refund_status='$BASE'\n";
});

function set_status($pdo, $pid, $status, $err = null) {
    $pdo->prepare("UPDATE payments SET refund_status = ?, refund_error = ? WHERE payment_id = ?")
        ->execute([$status, $err, $pid]);
}

// ── Login as admin ──────────────────────────────────────────────────
[$c, $b] = http("$base/index.php");
preg_match('/name="csrf-token" content="([^"]+)"/', $b, $m); $tok = $m[1] ?? '';
$adminEmail = $pdo->query("SELECT email FROM users WHERE role='pentadbir' LIMIT 1")->fetchColumn();
[$c, $b] = http("$base/auth/login.php", ['email'=>$adminEmail,'password'=>'password123','_csrf_token'=>$tok]);
$j = json_decode($b, true);
check('admin login', ($j['success'] ?? false) === true, "http=$c body=" . substr($b,0,120));

// ── [1] Served admin.php must now contain the client wiring ─────────
[$c, $page] = http("$base/dashboard/admin.php");
check('admin page 200', $c === 200, "http=$c");
check('nav link showTab(pulangan)', str_contains($page, "showTab('pulangan')"));
check('js function loadRefunds',   str_contains($page, 'function loadRefunds'));
check('js function filterRefunds', str_contains($page, 'function filterRefunds'));
check('js function renderRefunds', str_contains($page, 'function renderRefunds'));
check('js action retryRefund',     str_contains($page, 'async function retryRefund'));
check('js action markRefundCompleted', str_contains($page, 'async function markRefundCompleted'));
check('showTab lazy branch calls loadRefunds',
      (bool)preg_match("/name === 'pulangan'.*\{[^}]*loadRefunds\(\)/s", $page));

// ── Helper: query get_refunds and return decoded json ───────────────
function get_refunds($base, $status) {
    $url = "$base/dashboard/admin.php?action=get_refunds"
         . "&status=" . urlencode($status) . "&page=1&per_page=20";
    [$c, $b] = http($url);
    return [$c, json_decode($b, true)];
}
function returns_pid($data, $pid) {
    foreach (($data['refunds'] ?? []) as $r) if ((int)$r['payment_id'] === $pid) return true;
    return false;
}

// ── [2] pending_return filter now honored (L611 fix) ────────────────
set_status($pdo, $PID, 'pending_return');
[$c, $d] = get_refunds($base, 'pending_return');
check('get_refunds?status=pending_return http 200', $c === 200, "http=$c");
check('pending_return row returned (filter works, not ignored)', returns_pid($d, $PID), json_encode($d['refunds'] ?? 'none'));
check('summary has pending_return_count key', isset($d['summary']['pending_return_count']), json_encode($d['summary'] ?? []));
check('summary.pending_return_count == 1', ($d['summary']['pending_return_count'] ?? null) == 1, json_encode($d['summary'] ?? []));

// A DIFFERENT status must NOT return the pending_return row (proves the filter actually filters).
[$c, $d2] = get_refunds($base, 'failed');
check('status=failed excludes the pending_return row', !returns_pid($d2, $PID), json_encode($d2['refunds'] ?? []));

// ── [3] failed row renders with data + refund_error seed ────────────
set_status($pdo, $PID, 'failed', 'Gateway timeout');
[$c, $d] = get_refunds($base, 'failed');
check('get_refunds?status=failed returns the row', returns_pid($d, $PID), json_encode($d['refunds'] ?? 'none'));
$row = null; foreach (($d['refunds'] ?? []) as $r) if ((int)$r['payment_id'] === $PID) $row = $r;
check('row exposes refund_error for the Sebab column', ($row['refund_error'] ?? '') === 'Gateway timeout', json_encode($row));
check('row carries render fields (buyer, product, amount)',
      isset($row['buyer_name'], $row['product_name'], $row['amount']), json_encode($row));

// ── [4] 'all' (empty status) still returns everything non-none ──────
[$c, $d] = get_refunds($base, '');
check('get_refunds?status= (all) returns the row', returns_pid($d, $PID), json_encode($d['refunds'] ?? 'none'));

echo "\n==== RESULT: $PASS PASS / $FAIL FAIL ====\n";
exit($FAIL === 0 ? 0 : 1);
