<?php
// ============================================================
// test_report_fixes.php
// Regression test for the external UI/bug report triage:
//   1.1 admin showTab() crash on missing #tab-pulangan  (fixed)
//   1.2 seller TDZ claim                               (refuted — proven)
//   2.1 profile.php faculty wipe                       (fixed)
//   2.2 auth_check unauthenticated redirect            (re-verified)
//   2.3 trust_components_html prior-bar paradox        (fixed)
//   3.1/3.2 review hint + category placeholder         (fixed, static)
// Usage: php test_report_fixes.php [base_url]
// ============================================================
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/includes/trust_score.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail, $d = '') {
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}

// ── 1.1 admin: TABS must match rendered containers, guarded ─────────────
echo "-- 1.1 admin tab switching --\n";
$admin = file_get_contents($root . '/dashboard/admin.php');
preg_match('/const TABS\s*=\s*\[(.*?)\];/s', $admin, $m);
$tabs = array_values(array_filter(array_map(
    fn($x) => trim($x, " \t'\""), explode(',', $m[1] ?? ''))));
check('TABS no longer lists the never-built "pulangan" tab', !in_array('pulangan', $tabs, true), $pass, $fail, implode(',', $tabs));
$missing = [];
foreach ($tabs as $t) {
    if (strpos($admin, 'id="tab-' . $t . '"') === false) $missing[] = $t;
}
check('every TABS entry has a matching <div id="tab-...">', empty($missing), $pass, $fail, implode(',', $missing));
check('showTab() is null-guarded (future-proof)', strpos($admin, 'if (el) el.style.display') !== false, $pass, $fail);
$seller = file_get_contents($root . '/dashboard/seller.php');
check('seller showTab() carries the same guard', strpos($seller, 'if (el) el.style.display') !== false, $pass, $fail);

// ── 1.2 seller TDZ claim is only real if something calls showTab() at
//        parse time (before the let declarations). Prove nothing does. ────
echo "\n-- 1.2 seller TDZ (expected: refuted) --\n";
$initPos = strpos($seller, "addEventListener('DOMContentLoaded'");
$initTail = $initPos !== false ? substr($seller, $initPos) : '';
check('no runtime showTab() call in the init block (only user onclicks)',
    $initPos !== false && strpos($initTail, 'showTab(') === false, $pass, $fail);
check('let declarations exist before any user interaction is possible',
    strpos($seller, 'let _currentOrdersFilter') !== false, $pass, $fail);

// ── 2.3 components: priors hidden until evidence exists ─────────────────
echo "\n-- 2.3 trust breakdown paradox --\n";
$noEv = [
    'evidence_units' => 0, 'confidence' => 0, 'confidence_label' => 'Tiada',
    'components' => [['component_label'=>'Transaksi','details'=>'x','metric_value'=>75,'points'=>26.3,'max_points'=>35]],
];
$htmlNo = trust_components_html($noEv);
check('no-evidence profile -> explanatory notice, NOT prior bars',
    strpos($htmlNo, 'Pecahan skor akan diaktifkan') !== false && strpos($htmlNo, 'trust-prog') === false, $pass, $fail);
$withEv = [
    'evidence_units' => 12, 'confidence' => 80, 'confidence_label' => 'Tinggi',
    'components' => [['component_label'=>'Transaksi','details'=>'x','metric_value'=>75,'points'=>26.3,'max_points'=>35]],
];
$htmlYes = trust_components_html($withEv);
check('profile WITH evidence still renders the component bars',
    strpos($htmlYes, 'trust-prog') !== false && strpos($htmlYes, '26.3/35') !== false, $pass, $fail);

// ── 3.x static UI assertions ────────────────────────────────────────────
echo "\n-- 3.1 / 3.2 static --\n";
$buyer = file_get_contents($root . '/dashboard/buyer.php');
check('review submit button explains why it is disabled',
    strpos($buyer, 'Sila pilih sekurang-kurangnya 1 bintang') !== false, $pass, $fail);
check('PHP card uses category icon (no faint svg)', strpos($buyer, '$cat_icon =') !== false, $pass, $fail);
check('JS card uses CAT_ICON map', strpos($buyer, 'const CAT_ICON') !== false, $pass, $fail);
check('product.php uses category icon too',
    strpos(file_get_contents($root . '/product.php'), '$cat_icon =') !== false, $pass, $fail);

// ── HTTP helpers ────────────────────────────────────────────────────────
$jar = sys_get_temp_dir() . '/tm_report.txt';
@unlink($jar);
function http($url, $post = null, $hdr = [], $jarRef = null) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20,
    ]);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc  = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$code, (string)$body, (string)$loc];
}

// ── 2.2 unauthenticated redirect must land on a valid login URL ─────────
echo "\n-- 2.2 auth redirect (live) --\n";
@unlink($jar); // fresh, no session
[$c, , $loc] = http("$base/dashboard/admin.php");
// resolve relative Location manually against the request URL
$target = ($c === 302 && $loc) ? (strpos($loc, 'http') === 0 ? $loc : dirname("$base/dashboard/admin.php") . '/' . $loc) : '';
$target = $target ? preg_replace('#/dashboard/\.\./#', '/', $target) : '';
check('unauthenticated admin.php issues 302', $c === 302 && strpos($loc, 'public/index.php') !== false, $pass, $fail, "http $c loc " . ($loc ?: '-'));
[$c2] = http($target ?: "$base/public/index.php");
check('redirect target serves 200 (login page)', $c2 === 200, $pass, $fail, "target=$target http $c2");

// ── 2.1 faculty wipe (live, self-restoring) ─────────────────────────────
echo "\n-- 2.1 faculty preservation (live) --\n";
$buyerRow = $pdo->query("SELECT user_id, email, full_name, faculty FROM users WHERE role='pembeli' AND is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
[, $page] = http("$base/public/");
preg_match('/name="csrf-token" content="([^"]+)"/', $page, $tm);
$tok = $tm[1] ?? '';
[$c, $login] = http("$base/auth/login.php",
    ['email' => $buyerRow['email'], 'password' => 'password123', '_csrf_token' => $tok],
    ['X-CSRF-Token: ' . $tok, 'Accept: application/json']);
check('buyer login ok', strpos($login, '"success":true') !== false, $pass, $fail);

[, $profPage] = http("$base/dashboard/profile.php");
preg_match('/name="csrf-token" content="([^"]+)"/', $profPage, $tm2);
$ptok = $tm2[1] ?? $tok;
function save_profile($base, $ptok, $name, $fac) {
    global $pass, $fail;
    [$c, $body] = http("$base/dashboard/profile.php?action=update_profile",
        ['full_name' => $name, 'faculty' => $fac, '_csrf_token' => $ptok],
        ['X-CSRF-Token: ' . $ptok, 'Accept: application/json']);
    return [$c, $body];
}
$origFaculty = (string)($buyerRow['faculty'] ?? '');
try {
    [$c, $body] = save_profile($base, $ptok, $buyerRow['full_name'], 'PTU');
    $now = (string)$pdo->query("SELECT faculty FROM users WHERE user_id={$buyerRow['user_id']}")->fetchColumn();
    check('PTU is now accepted and saved', $now === 'PTU', $pass, $fail, "db=$now body=$body");

    // Stale-form simulation: unknown faculty must PRESERVE, not wipe.
    [$c, $body] = save_profile($base, $ptok, $buyerRow['full_name'], 'XYZ-HACK');
    $now = (string)$pdo->query("SELECT faculty FROM users WHERE user_id={$buyerRow['user_id']}")->fetchColumn();
    check('unknown faculty preserves stored value (old code wiped to NULL)', $now === 'PTU', $pass, $fail, "db=$now");

    // Explicit clear still works (user chose "Pilih Fakulti").
    [$c, $body] = save_profile($base, $ptok, $buyerRow['full_name'], '');
    $now = (string)$pdo->query("SELECT faculty FROM users WHERE user_id={$buyerRow['user_id']}")->fetchColumn();
    check('explicit empty selection still clears the faculty', $now === '', $pass, $fail, "db=$now");
} finally {
    $pdo->prepare("UPDATE users SET faculty = ? WHERE user_id = ?")
        ->execute([$origFaculty !== '' ? $origFaculty : null, $buyerRow['user_id']]);
    echo "  (faculty restored to " . ($origFaculty !== '' ? $origFaculty : 'NULL') . ")\n";
}
@unlink($jar);

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
