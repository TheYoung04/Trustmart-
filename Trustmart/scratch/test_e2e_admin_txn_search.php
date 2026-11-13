<?php
/**
 * scratch/test_e2e_admin_txn_search.php — LIVE E2E for Issue #10 (admin
 * transaction search generated invalid SQL). Drives the read-only
 * admin.php?action=get_transactions endpoint over HTTP and proves a NON-EMPTY
 * search now succeeds (previously the count/summary queries referenced the
 * buyer/seller/p aliases without joining them). Read-only → no cleanup needed.
 */
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail, $d = '') {
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}
$jar = sys_get_temp_dir() . '/tm_admtxn.txt'; @unlink($jar);
function http($url, $post = null, $hdr = []) {
    global $jar; $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar, CURLOPT_TIMEOUT=>20]);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$body];
}
function token_from($b) {
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $b, $m)) return $m[1];
    if (preg_match('/name="_csrf_token" value="([^"]+)"/', $b, $m)) return $m[1];
    return '';
}

echo "Target: $base\n";
$adminEmail = $pdo->query("SELECT email FROM users WHERE role='pentadbir' AND is_active=1 LIMIT 1")->fetchColumn();
// A search term guaranteed to have a match: a product name used by a real order.
$search = (string) $pdo->query(
    "SELECT p.name FROM transactions t JOIN products p ON p.product_id = t.product_id LIMIT 1")->fetchColumn();
echo "admin=$adminEmail search='$search'\n";

// ── Login as admin ──
[, $page] = http("$base/public/"); $tok = token_from($page);
if ($tok === '') { check("CSRF token from landing", false, $pass, $fail); echo "ABORT\n"; exit(1); }
http("$base/auth/login.php", ['email'=>$adminEmail,'password'=>'password123','_csrf_token'=>$tok],
     ['X-CSRF-Token: '.$tok, 'Accept: application/json']);
[, $dash] = http("$base/dashboard/admin.php");

function get_txns($base, $q) {
    [$c, $b] = http("$base/dashboard/admin.php?action=get_transactions&" . http_build_query($q));
    return [$c, json_decode($b, true), $b];
}

try {
    // ===== A: non-empty search must succeed (was invalid SQL) =====
    [$c, $j, $raw] = get_txns($base, ['search' => $search, 'per_page' => 100]);
    echo "\n== A: non-empty transaction search ==\n";
    check("A HTTP 200", $c === 200, $pass, $fail, "code=$c");
    check("A JSON success (no SQL error)", is_array($j) && ($j['success'] ?? false) === true, $pass, $fail, substr($raw, 0, 120));
    check("A returns matching rows", is_array($j['transactions'] ?? null) && count($j['transactions']) >= 1, $pass, $fail, "n=" . count($j['transactions'] ?? []));

    // count/summary/detail must now AGREE (all three carry the same joins)
    $total = (int)($j['pagination']['total'] ?? -1);
    $summaryCount = (int)($j['summary']['total_count'] ?? -2);
    $detailCount = count($j['transactions'] ?? []);
    check("A count == summary.total_count (consistency)", $total === $summaryCount, $pass, $fail, "count=$total summary=$summaryCount");
    check("A returned detail rows <= total", $detailCount <= $total, $pass, $fail, "detail=$detailCount total=$total");

    // ===== B: non-matching search returns 0 cleanly =====
    [$c, $j] = get_txns($base, ['search' => '__zz_no_such_term_zz__', 'per_page' => 20]);
    echo "\n== B: non-matching search ==\n";
    check("B HTTP 200 + success", $c === 200 && ($j['success'] ?? false) === true, $pass, $fail);
    check("B total == 0", (int)($j['pagination']['total'] ?? -1) === 0, $pass, $fail, "total=".($j['pagination']['total'] ?? '?'));

    // ===== C: combined filters (status + search) still valid =====
    [$c, $j] = get_txns($base, ['status' => 'Selesai', 'search' => $search, 'per_page' => 20]);
    echo "\n== C: status + search filter ==\n";
    check("C HTTP 200 + success", $c === 200 && ($j['success'] ?? false) === true, $pass, $fail);

    // ===== D: empty search (regression — no joins needed but must still work) =====
    [$c, $j] = get_txns($base, ['per_page' => 20]);
    echo "\n== D: empty search regression ==\n";
    check("D HTTP 200 + success", $c === 200 && ($j['success'] ?? false) === true, $pass, $fail, "code=$c");
} catch (Throwable $e) {
    check("no fatal exception", false, $pass, $fail, $e->getMessage());
}

echo "\n=================================\nRESULT: $pass passed, $fail failed\n=================================\n";
exit($fail === 0 ? 0 : 1);
