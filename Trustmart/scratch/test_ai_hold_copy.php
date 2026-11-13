<?php
/**
 * AI fraud-hold PANEL COPY guard for TrustMart.
 *
 * test_ai_hold_e2e.php proves the gate blocks and allows the order. This proves
 * the words a buyer reads match that reality in both directions, because the
 * consequence line used to be derived from fraud_risk alone: after PTU released
 * a hold the panel still announced "dihalang sementara" one click above a
 * checkout that succeeded.
 *
 *   1. held            -> "dihalang sementara", no release wording
 *   2. released        -> "melepaskan penahan" + who + when, no "dihalang"
 *   3. legacy release  -> still "dibenarkan", no dangling "oleh"/"pada"
 *   4. risk verdict    -> stays TINGGI/red after release (override, not rewrite)
 *
 * The target seller's AI columns are saved and restored in teardown.
 * Run: php scratch/test_ai_hold_copy.php   (needs Apache + MySQL)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only\n"); }
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail, $extra = '') {
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($extra && !$c ? "  $extra" : '') . "\n";
    $c ? $pass++ : $fail++;
}
function fetch($url, $post = null, $hdr = []) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$c, (string)$b];
}
/** visible text — markup stripped, whitespace collapsed (sentences wrap in HTML) */
function text(string $html): string {
    return preg_replace('/\s+/', ' ', strip_tags($html));
}
function setHold(PDO $pdo, int $seller, int $hold, ?int $by, ?string $at): void {
    $pdo->prepare("UPDATE trust_profiles
                      SET ai_hold=?, ai_hold_released_by=?, ai_hold_released_at=?
                    WHERE user_id=? AND profile_role='penjual'")
        ->execute([$hold, $by, $at, $seller]);
}

// A seller whose AI layer has actually run, plus one of their live products.
$prod = $pdo->query(
    "SELECT p.product_id, p.seller_id, u.full_name AS seller_name
       FROM products p
       JOIN users u ON u.user_id = p.seller_id
       JOIN trust_profiles tp ON tp.user_id = p.seller_id AND tp.profile_role = 'penjual'
      WHERE p.status = 'Aktif' AND tp.ai_trust_score IS NOT NULL
      ORDER BY tp.fraud_risk DESC, p.product_id ASC
      LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$prod) { echo "No AI-scored seller with a live product — aborting.\n"; exit(1); }
$seller = (int)$prod['seller_id'];

// Force the high-risk band so the TINGGI branch is the one under test.
$orig = $pdo->query("SELECT fraud_risk, ai_hold, ai_hold_released_by, ai_hold_released_at
                      FROM trust_profiles WHERE user_id=$seller AND profile_role='penjual'")
              ->fetch(PDO::FETCH_ASSOC);
$admin = $pdo->query("SELECT user_id, full_name FROM users
                      WHERE role='pentadbir' AND is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$jar = sys_get_temp_dir() . '/tm_hold_copy.txt'; @unlink($jar);
echo "product #{$prod['product_id']} · seller #$seller {$prod['seller_name']}"
   . ($admin ? " · admin #$admin[user_id] $admin[full_name]" : " · TIADA ADMIN") . "\n";

try {
    $pdo->prepare("UPDATE trust_profiles SET fraud_risk=60 WHERE user_id=? AND profile_role='penjual'")
        ->execute([$seller]);

    // Log in as a different user so the page renders in full.
    [$c, $page] = fetch("$base/public/");
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m); $tok = $m[1] ?? '';
    $buyerEmail = $pdo->query("SELECT email FROM users WHERE role='pembeli' AND is_active=1
                                AND user_id <> $seller LIMIT 1")->fetchColumn();
    [, $lb] = fetch("$base/auth/login.php",
        ['email' => $buyerEmail, 'password' => 'password123', '_csrf_token' => $tok],
        ['X-CSRF-Token: ' . $tok, 'Accept: application/json']);
    check('login pembeli untuk memaparkan halaman', str_contains($lb, '"success":true'), $pass, $fail, $buyerEmail);

    echo "\n== 1. penjual masih ditahan (ai_hold=1) ==\n";
    setHold($pdo, $seller, 1, null, null);
    [$c, $html] = fetch("$base/product.php?id={$prod['product_id']}");
    $t = text($html);
    check('halaman produk dipaparkan', $c === 200 && str_contains($t, 'Bagaimana kami mengesahkan penjual ini?'),
          $pass, $fail, "http $c");
    check('amaran: "dihalang sementara"', str_contains($t, 'Pembelian baharu daripada penjual ini dihalang sementara — admin PTU perlu menyemak dahulu.'),
          $pass, $fail);
    check('tiada wording pelepasan', !str_contains($t, 'melepaskan penahan'), $pass, $fail);

    echo "\n== 2. admin sudah semak dan lepaskan (ai_hold=0) ==\n";
    setHold($pdo, $seller, 0, (int)$admin['user_id'], '2026-10-28 09:40:00');
    [$c, $html] = fetch("$base/product.php?id={$prod['product_id']}");
    $t = text($html);
    check('"Pembelian dibenarkan" + "melepaskan penahan"',
          str_contains($t, 'Pembelian dibenarkan — admin PTU telah menyemak dan melepaskan penahan'),
          $pass, $fail);
    check('rekod siapa/bila', str_contains($t, 'pada 28 Oct 2026, 09:40 oleh '
          . htmlspecialchars($admin['full_name'], ENT_QUOTES, 'UTF-8')), $pass, $fail);
    check('"dihalang" sudah tiada', !str_contains($t, 'dihalang sementara'), $pass, $fail);
    check('amaran risiko kekal direkodkan', str_contains($t, 'Amaran risiko AI di atas masih kekal dalam rekod.'),
          $pass, $fail);

    echo "\n== 3. pelepasan lama tanpa audit ==\n";
    setHold($pdo, $seller, 0, null, null);
    [$c, $html] = fetch("$base/product.php?id={$prod['product_id']}");
    $t = text($html);
    check('tetap "dibenarkan" tanpa audit trail', str_contains($t, 'melepaskan penahan. Amaran risiko AI'),
          $pass, $fail, 'tiada "oleh"/"pada" tergantung');
    check('tiada "oleh" kosong', !str_contains($t, 'oleh .') && !str_contains($t, 'pada  .'), $pass, $fail);

    echo "\n== 4. verdict AI tidak ditulis semula ==\n";
    check('risiko masih TINGGI — 60% selepas dilepaskan', str_contains($t, 'Risiko penipuan: TINGGI — 60%'), $pass, $fail);
} finally {
    $pdo->prepare("UPDATE trust_profiles
                      SET fraud_risk=?, ai_hold=?, ai_hold_released_by=?, ai_hold_released_at=?
                    WHERE user_id=? AND profile_role='penjual'")
        ->execute([$orig['fraud_risk'], $orig['ai_hold'], $orig['ai_hold_released_by'],
                   $orig['ai_hold_released_at'], $seller]);
    $now = $pdo->query("SELECT fraud_risk, ai_hold FROM trust_profiles
                         WHERE user_id=$seller AND profile_role='penjual'")->fetch(PDO::FETCH_ASSOC);
    echo "\n  profil penjual dipulihkan: fraud_risk={$now['fraud_risk']} ai_hold={$now['ai_hold']}"
       . " (asal {$orig['fraud_risk']}/{$orig['ai_hold']})\n";
    @unlink($jar);
}

printf("\n== %d passed, %d failed ==\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
