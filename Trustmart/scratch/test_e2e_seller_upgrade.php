<?php
/**
 * End-to-end proof of the pembeli -> penjual upgrade flow over real HTTP:
 *   1. temp pembeli applies via profile.php (request_seller_role)
 *   2. account becomes penjual in 'Menunggu' state (buyer data untouched)
 *   3. seller dashboard reachable, selling gated until approval
 *   4. application lands in the admin Pengesahan Penjual queue
 *   5. admin approves -> 'Disahkan' (selling unlocked)
 *   6. double-apply / re-apply after approval is rejected (403)
 * Self-cleaning: the temp user is removed in teardown.
 * Run: php scratch/test_e2e_seller_upgrade.php   (needs Apache + MySQL)
 */

$base = 'http://localhost/Trustmart';
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail) { echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . "\n"; $c ? $pass++ : $fail++; }
function fetch($url, $post = null, $hdr = [], $jar = null) {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false];
    if ($jar) { $opts[CURLOPT_COOKIEJAR] = $jar; $opts[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $opts);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$c, (string)$b];
}
function csrf_token($url, $jar) {
    global $base;
    [$c, $page] = fetch($url, null, [], $jar);
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m);
    return [$m[1] ?? '', $page];
}

$tmpId = 0;
$jar  = sys_get_temp_dir() . '/tm_upgrade_u.txt';  @unlink($jar);
$jarA = sys_get_temp_dir() . '/tm_upgrade_a.txt';  @unlink($jarA);

try {
    // ── 0. Create a temp active pembeli (bypasses email activation) ──
    $email = 'uptest' . random_int(100000, 999999) . '@student.uthm.edu.my';
    $hash = password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("INSERT INTO users (full_name, matric_no, email, password_hash, role, faculty,
                                     is_active, is_verified, verification_status, trust_score)
                   VALUES ('UPT Empuji', CONCAT('UPT', LAST_INSERT_ID()), ?, ?, 'pembeli', 'FKEE',
                           1, 0, 'Menunggu', 100.00)")
        ->execute([$email, $hash]);
    // matric_no via LAST_INSERT_ID() inside the same statement is unreliable;
    // fix it explicitly.
    $tmpId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE users SET matric_no = ? WHERE user_id = ?")->execute(['UPT' . $tmpId, $tmpId]);
    echo "temp pembeli #{$tmpId} {$email}\n";

    // ── 1. Login + apply ──
    [$tok] = csrf_token("$base/public/", $jar);
    [$c] = fetch("$base/auth/login.php", ['email'=>$email, 'password'=>'password123', '_csrf_token'=>$tok],
                 ['X-CSRF-Token: '.$tok, 'Accept: application/json'], $jar);
    check("temp user logs in (http $c)", $c === 200 || $c === 302, $pass, $fail);

    [$tok, $page] = csrf_token("$base/dashboard/profile.php", $jar);
    check("profile shows 'Mahu Jadi Penjual' to pembeli", str_contains($page, 'Mahu Jadi Penjual'), $pass, $fail);

    [$c, $body] = fetch("$base/dashboard/profile.php?action=request_seller_role", [],
                        ['X-CSRF-Token: '.$tok, 'Accept: application/json'], $jar);
    $j = json_decode($body, true);
    check("application accepted (http $c)", $c === 200 && ($j['success'] ?? false), $pass, $fail);

    // ── 2. DB state after apply ──
    $u = $pdo->query("SELECT role, verification_status, is_verified FROM users WHERE user_id=$tmpId")->fetch(PDO::FETCH_ASSOC);
    check("role now penjual", $u['role'] === 'penjual', $pass, $fail);
    check("verification Menunggu, not yet approved", $u['verification_status'] === 'Menunggu' && (int)$u['is_verified'] === 0, $pass, $fail);

    // ── 3. Seller dashboard reachable + pending banner shown ──
    [$c, $page] = fetch("$base/dashboard/seller.php", null, [], $jar);
    check("seller dashboard opens (http $c)", $c === 200, $pass, $fail);
    check("pending-verification banner visible", str_contains($page, 'Menunggu Pengesahan'), $pass, $fail);

    // ── 4. Admin queue integration ──
    [$tokA] = csrf_token("$base/public/", $jarA);
    [$c] = fetch("$base/auth/login.php", ['email'=>'admin@uthm.edu.my', 'password'=>'password123', '_csrf_token'=>$tokA],
                 ['X-CSRF-Token: '.$tokA, 'Accept: application/json'], $jarA);
    [$tokA, $pageA] = csrf_token("$base/dashboard/admin.php", $jarA);
    [$c, $body] = fetch("$base/dashboard/admin.php?action=get_sellers&filter=pending", [],
                        ['X-CSRF-Token: '.$tokA, 'Accept: application/json'], $jarA);
    $jq = json_decode((string)$body, true);
    $inQueue = false;
    foreach (($jq['sellers'] ?? []) as $r) { if ((int)($r['user_id'] ?? 0) === $tmpId) $inQueue = true; }
    check("application appears in Pengesahan Penjual queue (http $c)", $c === 200 && $inQueue, $pass, $fail);

    // ── 5. Admin approves -> unlocked ──
    [$c, $body] = fetch("$base/dashboard/admin.php?action=verify_seller",
                        ['user_id'=>$tmpId, 'action_type'=>'approve', '_csrf_token'=>$tokA],
                        ['X-CSRF-Token: '.$tokA, 'Accept: application/json'], $jarA);
    $j = json_decode($body, true);
    check("admin approve succeeds (http $c)", $c === 200 && ($j['success'] ?? false), $pass, $fail);
    $st = $pdo->query("SELECT verification_status, is_verified FROM users WHERE user_id=$tmpId")->fetch(PDO::FETCH_ASSOC);
    check("status Disahkan after approval", $st['verification_status'] === 'Disahkan' && (int)$st['is_verified'] === 1, $pass, $fail);

    // ── 6. Re-apply after approval must be refused ──
    [$tok] = csrf_token("$base/dashboard/profile.php", $jar);
    [$c, $body] = fetch("$base/dashboard/profile.php?action=request_seller_role", [],
                        ['X-CSRF-Token: '.$tok, 'Accept: application/json'], $jar);
    check("double-apply refused (403)", $c === 403, $pass, $fail);
} finally {
    // ── Teardown: remove every trace of the temp user ──
    if ($tmpId) {
        $pdo->prepare("DELETE FROM trust_profiles WHERE user_id=?")->execute([$tmpId]);
        $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$tmpId]);
        echo "  temp user removed\n";
    }
    @unlink($jar); @unlink($jarA);
}

echo "\n== RESULT: $pass passed, $fail failed ==\n";
exit($fail === 0 ? 0 : 1);
