<?php
/**
 * Guard for the plain-language trust-score explanation
 * (trust_explained_html in includes/trust_score.php).
 *
 * v3 (TM-TRUST-3.0, TikTok-style pillars) removed the confidence discount:
 * the copy must narrate the pillars + 90-day window and state that confidence
 * is information, not a score cut. This checks all three evidence states and
 * that the dashboards render it live.
 *
 * Run: php scratch/test_trust_explained.php   (needs Apache + MySQL)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only\n"); }
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/includes/trust_score.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail, $extra = '') {
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($extra && !$c ? "  $extra" : '') . "\n";
    $c ? $pass++ : $fail++;
}
function fetch($url, $post = null, $hdr = [], $jar = null) {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false];
    if ($jar) { $opts[CURLOPT_COOKIEJAR] = $jar; $opts[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $opts);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$c, (string)$b];
}
function text(string $html): string {
    return preg_replace('/\s+/', ' ', strip_tags($html));
}

echo "== 1. belum ada bukti ==\n";
$t = text(trust_explained_html([
    'evidence_units' => 0, 'raw_score' => 80.0, 'score' => 0.0,
    'confidence' => 0.0, 'distinct_counterparties' => 0,
], 'penjual'));
check('menjelaskan "Baharu" bukan penalti',
      str_contains($t, 'Skor anda belum dikira') && str_contains($t, 'bukan penalti'), $pass, $fail, $t);
check('tiada formula tinggal', !str_contains($t, '×'), $pass, $fail);

echo "\n== 2. bukti terhad (penjual kecil, skor tidak dipotong) ==\n";
$t = text(trust_explained_html([
    'evidence_units' => 3, 'raw_score' => 78.5, 'score' => 78.5,
    'confidence' => 57.81, 'confidence_label' => 'Rendah',
    'distinct_counterparties' => 1, 'profile_role' => 'penjual',
], 'penjual'));
check('memaparkan skor penuh tanpa potongan', str_contains($t, 'Kualiti rekod anda: 78.5/100'), $pass, $fail, $t);
check('menyebut pillar penjual (40/30/25/5)',
      str_contains($t, 'pesanan yang selesai (40 mata)')
      && str_contains($t, 'respons pantas (30 mata)'), $pass, $fail);
check('keyakinan = maklumat, bukan potongan',
      str_contains($t, 'bukan potongan skor') && str_contains($t, 'Rendah (58%)'), $pass, $fail);
check('tingkap 90 hari disebut', str_contains($t, '90 hari terkini'), $pass, $fail);
check('ada cara menaikkannya', str_contains($t, 'Cara menaikkannya'), $pass, $fail);
check('tiada formula tinggal', !str_contains($t, '×'), $pass, $fail);

echo "\n== 3. pembeli dengan bukti cukup ==\n";
$t = text(trust_explained_html([
    'evidence_units' => 12, 'raw_score' => 78.5, 'score' => 78.5,
    'confidence' => 100.0, 'confidence_label' => 'Tinggi',
    'distinct_counterparties' => 6, 'profile_role' => 'pembeli',
], 'pembeli'));
check('pillar pembeli (70/25/5)', str_contains($t, 'penyelesaian pesanan (70 mata)'), $pass, $fail, $t);
check('guna istilah rakan niaga yang betul (pembeli)',
      str_contains($t, 'berniaga dengan 6 pembeli berbeza'), $pass, $fail, $t);
check('keyakinan tinggi dipaparkan', str_contains($t, 'Tinggi (100%)'), $pass, $fail);

$jar = sys_get_temp_dir() . '/tm_explained_u.txt'; @unlink($jar);
$tmpId = 0;
try {
    echo "\n== 4. paparan hidup (buyer.php + seller.php) ==\n";
    $email = 'exp' . random_int(100000, 999999) . '@student.uthm.edu.my';
    $hash  = password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("INSERT INTO users (full_name, matric_no, email, password_hash, role, faculty,
                                      is_active, is_verified, verification_status, trust_score)
                   VALUES ('Penjelasan Uji', 'EXP0', ?, ?, 'pembeli', 'FKEE', 1, 1, 'Disahkan', 100.00)")
        ->execute([$email, $hash]);
    $tmpId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE users SET matric_no=? WHERE user_id=?")->execute(['EXP' . $tmpId, $tmpId]);

    [, $page] = fetch("$base/public/", null, [], $jar);
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m); $tok = $m[1] ?? '';
    [, $lb] = fetch("$base/auth/login.php",
        ['email' => $email, 'password' => 'password123', '_csrf_token' => $tok],
        ['X-CSRF-Token: ' . $tok, 'Accept: application/json'], $jar);
    check('login hidup', str_contains($lb, '"success":true'), $pass, $fail);

    [$c, $html] = fetch("$base/dashboard/buyer.php", null, [], $jar);
    $t = text($html);
    check('buyer.php memaparkan penjelasan kosong-bukti',
          $c === 200 && str_contains($t, 'Skor anda belum dikira'), $pass, $fail, "http $c");
    check('label "Kualiti rekod" ada, "Skor asas" tiada',
          str_contains($t, 'Kualiti rekod') && !str_contains($t, 'Skor asas'), $pass, $fail);
    check('formula lama sudah pergi', !str_contains($t, 'skor asas berwajaran'), $pass, $fail);

    $pdo->prepare("UPDATE users SET role='penjual' WHERE user_id=?")->execute([$tmpId]);
    [$c, $html] = fetch("$base/dashboard/seller.php", null, [], $jar);
    $t = text($html);
    check('seller.php memaparkan penjelasan yang sama',
          $c === 200 && str_contains($t, 'Skor anda belum dikira'), $pass, $fail, "http $c");
    check('seller: tiada "Skor asas" / tiada formula',
          !str_contains($t, 'Skor asas') && !str_contains($t, 'tahap keyakinan.'), $pass, $fail);
} finally {
    if ($tmpId) {
        $pdo->prepare("DELETE FROM trust_profiles WHERE user_id=?")->execute([$tmpId]);
        $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$tmpId]);
        echo "temp user removed\n";
    }
    @unlink($jar);
}

printf("\n== %d passed, %d failed ==\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
