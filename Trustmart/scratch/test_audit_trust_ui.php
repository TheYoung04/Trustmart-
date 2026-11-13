<?php
/**
 * scratch/test_audit_trust_ui.php — LIVE guard for audit #2, #3a, #3b, #9.
 *
 * #2   a seller the AI layer is holding must WARN the buyer up front, not only
 *      refuse at "Buat Pesanan": product.php shows the hold notice and hides the
 *      order controls for a held seller, and shows normal controls (no notice)
 *      for a clean one. Plus the hold predicate itself as a pure truth table.
 * #3a  the admin "Analitik Platform" leaderboard ranks on the SAME number every
 *      buyer surface uses — COALESCE(tp.ai_trust_score, tp.score) — not the
 *      legacy users.trust_score mirror.
 * #3b  the AI score is kept consistent (final = baseline + adjustment, never a
 *      stale/NULL value that contradicts the database) and the refresh is wired
 *      into the review + dispute write paths, not only the cron worker.
 * #9   a suspended account is told it was "digantung oleh PTU", not sent to look
 *      for an activation link — while a genuinely un-activated account still
 *      gets the activation message (positive control).
 *
 * Run: C:\xampp\php\php.exe scratch/test_audit_trust_ui.php [http://localhost/Trustmart]
 * Self-cleans: restores the probe user's is_active/activation_token; creates none.
 */
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/includes/ai_trust.php';

$pass = 0; $fail = 0;
function check($n, $c, $d = '') {
    global $pass, $fail;
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d !== '' ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}
function http($url, $jar, $post = null, $hdr = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 25]);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$c, (string) $b];
}
function token_from($b) {
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $b, $m)) return $m[1];
    if (preg_match('/name="_csrf_token" value="([^"]+)"/', $b, $m)) return $m[1];
    return '';
}

echo "== TrustMart audit guard: AI-hold visibility + leaderboard + score freshness + suspension (#2 #3a #3b #9) ==\n";
echo "Target: $base\n";
$jar_any = sys_get_temp_dir() . '/tm_ui_any.txt'; @unlink($jar_any);
$jar_buyer = sys_get_temp_dir() . '/tm_ui_buyer.txt'; @unlink($jar_buyer);
$jar_admin = sys_get_temp_dir() . '/tm_ui_admin.txt'; @unlink($jar_admin);
$THR = defined('AI_TRUST_HOLD_RISK') ? (float) AI_TRUST_HOLD_RISK : 101;
echo "AI_TRUST_ENABLED=" . (AI_TRUST_ENABLED ? '1' : '0') . " HOLD_RISK=$THR\n";
[, $probe] = http("$base/public/", $jar_any);
if ($probe === '') { echo "TIADA respons dari $base/public/ — nyalakan Apache dulu.\n"; exit(2); }
$tok0 = token_from($probe);

/* ══════════════════════════════════════════════════════════════════════════
   #2 — the hold predicate as a pure truth table (no data, no network needed)
   ══════════════════════════════════════════════════════════════════════════ */
echo "\n== #2: aturan penahan AI (truth table) ==\n";
if (AI_TRUST_ENABLED && $THR <= 100) {
    check("held bila risiko >= ambang DAN ai_hold=1", ai_trust_hold_state($THR, 1) === true);
    check("TIDAK held bila risiko di bawah ambang", ai_trust_hold_state($THR - 0.01, 1) === false);
    check("TIDAK held bila admin sudah lepas (ai_hold=0)", ai_trust_hold_state($THR, 0) === false);
    check("fail-open bila profil belum dikira (NULL)", ai_trust_hold_state(null, 1) === false);
    check("notice hanya muncul bila benar-benar held",
        ai_trust_hold_notice_html(['fraud_risk' => $THR, 'ai_hold' => 1]) !== '' &&
        ai_trust_hold_notice_html(['fraud_risk' => $THR, 'ai_hold' => 0]) === '');
} else {
    check("AI layer OFF => tiada penahan (fail-open)", ai_trust_hold_state(100, 1) === false);
}

/* ══════════════════════════════════════════════════════════════════════════
   #3b — score freshness invariants + write-path wiring
   ══════════════════════════════════════════════════════════════════════════ */
echo "\n== #3b: kesegaran skor AI + wiring tulis ==\n";
$impossible = (int) $pdo->query("SELECT COUNT(*) FROM trust_profiles
    WHERE ai_trust_score IS NOT NULL
      AND ABS(ai_trust_score - (score + ai_adjustment)) > 0.05")->fetchColumn();
check("#3b tiada barus mustahil (final = asas + adjustment)", $impossible === 0, "baki=$impossible");

$null_stale = (int) $pdo->query("SELECT COUNT(*) FROM trust_profiles
    WHERE ai_calculated_at IS NOT NULL AND ai_trust_score IS NULL")->fetchColumn();
check("#3b tiada profil 'dikira' tapi skornya NULL (fail-open)", $null_stale === 0, "baki=$null_stale");

check("#3b helper ai_trust_refresh_after_write wujud", function_exists('ai_trust_refresh_after_write'));
$buyer_src = (string) @file_get_contents($root . '/dashboard/buyer.php');
$admin_src = (string) @file_get_contents($root . '/dashboard/admin.php');
check("#3b dihubungkan pada alur ulasan pembeli (submit_review)", stripos($buyer_src, 'ai_trust_refresh_after_write') !== false);
check("#3b dihubungkan pada alur penyelesaian aduan (resolve_dispute)", stripos($admin_src, 'ai_trust_refresh_after_write') !== false);

/* ══════════════════════════════════════════════════════════════════════════
   #9 — suspension message says "digantung", not "activate"
   ══════════════════════════════════════════════════════════════════════════ */
echo "\n== #9: mesej akaun digantung vs belum diaktif ==\n";
$u9 = $pdo->query("SELECT user_id, email, is_active, activation_token FROM users
    WHERE role='pembeli' AND is_active=1 ORDER BY user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$u9) {
    check("sedia akaun pembeli untukProbe #9", false, 'tiada pengguna');
} else {
    $restore = $pdo->prepare("UPDATE users SET is_active=?, activation_token=? WHERE user_id=?");
    try {
        // suspended by PTU: inactive + activation_token already cleared.
        $pdo->prepare("UPDATE users SET is_active=0, activation_token=NULL WHERE user_id=?")->execute([$u9['user_id']]);
        [$c1, $b1] = http("$base/auth/login.php", $jar_any,
            ['email' => $u9['email'], 'password' => 'password123', '_csrf_token' => $tok0],
            ['X-CSRF-Token: ' . $tok0, 'Accept: application/json']);
        $m1 = json_decode($b1, true)['message'] ?? $b1;
        check("#9 yang digantung => 403", $c1 === 403, "code=$c1");
        check("#9 mesej menyebut 'digantung'", stripos($m1, 'digantung') !== false, substr($m1, 0, 70));
        check("#9 mesej TIDAK menyuruh 'diaktifkan'/pautan pengaktifan",
            stripos($m1, 'belum diaktifkan') === false && stripos($m1, 'pengaktifan') === false, substr($m1, 0, 70));

        // positive control: genuinely never activated → activation link is correct.
        $pdo->prepare("UPDATE users SET is_active=0, activation_token='TM_PROBE_TOKEN' WHERE user_id=?")->execute([$u9['user_id']]);
        [$c2, $b2] = http("$base/auth/login.php", $jar_any,
            ['email' => $u9['email'], 'password' => 'password123', '_csrf_token' => $tok0],
            ['X-CSRF-Token: ' . $tok0, 'Accept: application/json']);
        $m2 = json_decode($b2, true)['message'] ?? $b2;
        check("#9 belum diaktif => mesej 'belum diaktifkan' (kawalan positif)",
            $c2 === 403 && stripos($m2, 'belum diaktifkan') !== false, substr($m2, 0, 70));
    } finally {
        $restore->execute([$u9['is_active'], $u9['activation_token'], $u9['user_id']]);
        echo "  (restore #9: user {$u9['user_id']} is_active={$u9['is_active']} token=" .
            ($u9['activation_token'] === null ? 'NULL' : 'ada') . ")\n";
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   #3a — admin leaderboard = COALESCE(ai_trust_score, score), not legacy mirror
   ══════════════════════════════════════════════════════════════════════════ */
echo "\n== #3a: papan pendahulu Analitik guna COALESCE ==\n";
$adminEmail = $pdo->query("SELECT email FROM users WHERE role='pentadbir' AND is_active=1 LIMIT 1")->fetchColumn();
[, $pa] = http("$base/public/", $jar_admin);
$tokA = token_from($pa);
http("$base/auth/login.php", $jar_admin, ['email' => $adminEmail, 'password' => 'password123', '_csrf_token' => $tokA],
    ['X-CSRF-Token: ' . $tokA, 'Accept: application/json']);
[, $ga] = http("$base/dashboard/admin.php?action=get_analytics", $jar_admin);
$ja = json_decode($ga, true);
check("get_analytics berjaya", is_array($ja) && ($ja['success'] ?? false) && isset($ja['top_sellers']), substr($ga, 0, 60));

// Independent truth: seller name => (coalesce shown everywhere, legacy mirror).
$rows = $pdo->query("SELECT u.full_name, COALESCE(tp.ai_trust_score, tp.score, 0) AS coalesce_score, u.trust_score AS legacy
    FROM users u JOIN trust_profiles tp ON tp.user_id=u.user_id AND tp.profile_role='penjual'")->fetchAll(PDO::FETCH_ASSOC);
$by_name = [];
foreach ($rows as $r) { $by_name[$r['full_name']] = $r; }
$diverge = array_values(array_filter($rows, fn($r) => abs((float)$r['coalesce_score'] - (float)$r['legacy']) > 0.05));

if (is_array($ja['top_sellers'] ?? null)) {
    $all_match = true; $offender = '';
    foreach ($ja['top_sellers'] as $s) {
        $nm = $s['full_name'];
        if (isset($by_name[$nm])) {
            if (abs((float)$s['trust_score'] - (float)$by_name[$nm]['coalesce_score']) > 0.06) {
                $all_match = false; $offender = "$nm api=" . $s['trust_score'] . " coalesce=" . $by_name[$nm]['coalesce_score'];
            }
        }
    }
    check("#3a setiap baris leaderboard = COALESCE(ai,score)", $all_match, $offender);
    if ($diverge) {
        $d = $diverge[0];
        $shown = null;
        foreach ($ja['top_sellers'] as $s) { if ($s['full_name'] === $d['full_name']) { $shown = (float)$s['trust_score']; } }
        check("#3a penjual yang BEZA mirror/COALESCE dipaparkan ikut COALESCE (bukan legacy {$d['legacy']})",
            $shown !== null && abs($shown - (float)$d['coalesce_score']) <= 0.06,
            "{$d['full_name']}: paparkan=" . json_encode($shown) . " coalesce={$d['coalesce_score']} legacy={$d['legacy']}");
    } else {
        check("#3a (tiada penjual yang mirror-nya berbeza — COALESCE tetap dipakai)", $all_match);
    }
} else {
    check("top_sellers bentuk betul", false, substr($ga, 0, 60));
}

/* ══════════════════════════════════════════════════════════════════════════
   #2 — product.php visibility: held seller warns & hides controls; clean one does not
   ══════════════════════════════════════════════════════════════════════════ */
echo "\n== #2: product.php nampak penahan ==\n";
// log in as a pembeli who is NOT the seller of the products we probe.
$buyerRow = $pdo->query("SELECT user_id, email FROM users WHERE role='pembeli' AND is_active=1
    AND user_id NOT IN (SELECT seller_id FROM products WHERE status='Aktif') ORDER BY user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC)
    ?: $pdo->query("SELECT user_id, email FROM users WHERE role='pembeli' AND is_active=1 ORDER BY user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
[, $pb] = http("$base/public/", $jar_buyer); $tokB = token_from($pb);
http("$base/auth/login.php", $jar_buyer, ['email' => $buyerRow['email'], 'password' => 'password123', '_csrf_token' => $tokB],
    ['X-CSRF-Token: ' . $tokB]);

// find a held active product and a clean active product using the app's own predicate.
$heldProd = $cleanProd = null;
$cands = $pdo->query("SELECT p.product_id, p.seller_id FROM products p
    WHERE p.status='Aktif' AND p.stock>0 ORDER BY p.product_id ASC LIMIT 80")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cands as $c) {
    if ((int) $c['seller_id'] === (int) $buyerRow['user_id']) continue; // avoid own/self-exclusion edge
    $held = ai_trust_seller_hold($pdo, (int) $c['seller_id'], 'penjual');
    if ($held && !$heldProd) $heldProd = $c;
    if (!$held && !$cleanProd) $cleanProd = $c;
    if ($heldProd && $cleanProd) break;
}

if ($heldProd) {
    [$ch, $bh] = http("$base/product.php?id={$heldProd['product_id']}", $jar_buyer);
    check("#2 produk penjual DITAHAN memaparkan notis 'siasatan PTU'", $ch === 200 && stripos($bh, 'siasatan PTU') !== false, "code=$ch");
    check("#2 produk DITAHAN tiada 'Buat Pesanan' / kawalan pesanan", stripos($bh, 'place-order-btn') === false, 'tiada button');
} else {
    check("#2 (tiada produk penjual ditahan ketika ini — lompat semak endpoint)", true, 'predicate sahaja');
}
if ($cleanProd) {
    [$cc, $bc] = http("$base/product.php?id={$cleanProd['product_id']}", $jar_buyer);
    check("#2 produk PENJUAL BERSIH ada kawalan 'Buat Pesanan'", $cc === 200 && stripos($bc, 'place-order-btn') !== false, "code=$cc");
    check("#2 produk bersih TIDAK memaparkan notis penahan", stripos($bc, 'sedang dalam siasatan PTU') === false);
}

echo "\n==================================\n";
echo "RESULT: $pass passed, $fail failed\n";
echo "==================================\n";
exit($fail === 0 ? 0 : 1);
