<?php
/**
 * Catalogue variety + image guard for TrustMart.
 *
 * Checks what a real pembeli actually sees after scratch/seed_products_demo.php:
 *   1. every one of the six categories returns listings
 *   2. no active, buyable product is missing a photo
 *   3. every stored image_path is served by Apache as an image
 *   4. each product page renders the name and wires up the photo
 * Read-only apart from a temp buyer that is deleted in teardown.
 *
 * Run: php scratch/test_catalogue_variety.php   (needs Apache + MySQL)
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
function fetch($url, $post = null, $hdr = [], $jar = null) {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
             CURLOPT_NOBODY         => false];
    if ($jar) { $opts[CURLOPT_COOKIEJAR] = $jar; $opts[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $opts);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$c, (string)$b, $type];
}

$CATS = ['Elektronik', 'Buku', 'Pakaian', 'Makanan', 'Perkhidmatan', 'Lain-lain'];
$jar  = sys_get_temp_dir() . '/tm_catalogue_u.txt'; @unlink($jar);
$tmpId = 0;

try {
    // temp pembeli so the catalogue is visible without self-exclusion noise
    $email = 'cat' . random_int(100000, 999999) . '@student.uthm.edu.my';
    $hash = password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("INSERT INTO users (full_name, matric_no, email, password_hash, role, faculty,
                                     is_active, is_verified, verification_status, trust_score)
                   VALUES ('Catalogue Uji', 'CAT0', ?, ?, 'pembeli', 'FKEE', 1, 0, 'Menunggu', 100.00)")
        ->execute([$email, $hash]);
    $tmpId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE users SET matric_no=? WHERE user_id=?")->execute(['CAT' . $tmpId, $tmpId]);

    [, $page] = fetch("$base/public/", null, [], $jar);
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m); $tok = $m[1] ?? '';
    fetch("$base/auth/login.php",
          ['email' => $email, 'password' => 'password123', '_csrf_token' => $tok],
          ['X-CSRF-Token: ' . $tok, 'Accept: application/json'], $jar);

    // ── 1. every category has something to show ───────────────
    echo "== 1. Kategori (apa yang pembeli nampak) ==\n";
    $visible = [];
    foreach ($CATS as $cat) {
        [, $body] = fetch("$base/dashboard/buyer.php?action=get_products&category=" . rawurlencode($cat)
                          . "&per_page=50", null, ['Accept: application/json'], $jar);
        $j = json_decode($body, true);
        $list = $j['products'] ?? [];
        foreach ($list as $p) $visible[(int)$p['product_id']] = $p;
        check("kategori '$cat' ada listing", count($list) > 0, $pass, $fail,
              '(' . count($list) . ' produk)');
    }

    // ── 2. no buyable product is photo-less ───────────────────
    echo "\n== 2. Gambar lengkap ==\n";
    $noimg = array_filter($visible, fn($p) => empty($p['image_path']));
    check('semua ' . count($visible) . " produk nampak pembeli ada gambar",
          count($noimg) === 0, $pass, $fail,
          'tiada gambar: ' . implode(', ', array_column($noimg, 'name')));

    // ── 3. every stored image actually loads ──────────────────
    echo "\n== 3. Fail gambar dihantar Apache ==\n";
    $rows = $pdo->query("SELECT product_id, name, image_path FROM products
                          WHERE status='Aktif' AND image_path IS NOT NULL AND image_path<>''")->fetchAll(PDO::FETCH_ASSOC);
    $bad = [];
    foreach ($rows as $r) {
        [$c, , $type] = fetch("$base/uploads/" . $r['image_path'], null, [], $jar);
        if ($c !== 200 || stripos((string)$type, 'image/') !== 0) {
            $bad[] = "#{$r['product_id']} {$r['image_path']} (http $c, $type)";
        }
    }
    check('semua ' . count($rows) . ' imej produk dimuat (200 + image/*)',
          $bad === [], $pass, $fail, implode(' | ', $bad));

    // ── 4. product page wires the photo in ────────────────────
    echo "\n== 4. Halaman produk ==\n";
    $badPage = [];
    foreach ($visible as $pid => $p) {
        [$c, $html] = fetch("$base/product.php?id=$pid", null, [], $jar);
        $needle = 'uploads/' . $p['image_path'];
        if ($c !== 200 || !str_contains($html, htmlspecialchars($p['name'], ENT_QUOTES))
            || !str_contains($html, $needle)) {
            $badPage[] = "#$pid {$p['name']} (http $c)";
        }
    }
    check('setiap halaman produk memaparkan nama + <img> betul',
          $badPage === [], $pass, $fail, implode(' | ', $badPage));

    // ── 5. the explore header must state the catalogue total ────
    // It used to print count($featured) — one page of the grid — so a 19-item
    // catalogue announced itself as "6 produk tersedia".
    echo "\n== 5. Kiraan label Jelajah Produk ==\n";
    [$c, $dash] = fetch("$base/dashboard/buyer.php", null, [], $jar);
    preg_match('/(\d+) produk tersedia/', $dash, $ml); $label = (int)($ml[1] ?? -1);
    $expected = count($visible);
    check("label menunjukkan jumlah sebenar ($expected)",
          $c === 200 && $label === $expected, $pass, $fail, "label=$label");
    $cards = preg_match_all('/class="prod-card" onclick="openProduct\(\d+\)/', $dash);
    check("paparan pertama memaparkan halaman grid ($cards kad, had 12)",
          $cards > 0 && $cards <= 12 && $cards === min(12, $expected), $pass, $fail,
          "cards=$cards expected=" . min(12, $expected));
    check("paginasi dicetus pada load jika lebih satu halaman",
          str_contains($dash, "#prod-grid .prod-card').length < $expected"), $pass, $fail);

    printf("\n== %d passed, %d failed ==\n", $pass, $fail);
} finally {
    if ($tmpId) {
        $pdo->prepare("DELETE FROM trust_profiles WHERE user_id=?")->execute([$tmpId]);
        $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$tmpId]);
    }
    @unlink($jar);
    echo "temp user removed\n";
}
