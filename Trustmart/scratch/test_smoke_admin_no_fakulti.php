<?php
// ============================================================
// scratch/test_smoke_admin_no_fakulti.php
// Verifies that in profile.php the Fakulti info-row and the
// edit-faculty dropdown are hidden for pentadbir but still
// visible for pembeli/penjual. Read-only, self-cleaning.
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$base = 'http://localhost/Trustmart';
$jar  = sys_get_temp_dir() . '/tm_no_fakulti_' . getmypid() . '.cookie';
@unlink($jar);

function curl(string $url, ?array $post = null): array {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body];
}

$pass = 0; $fail = 0;
function check(string $n, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  $n\n"; }
    else    { $fail++; echo "  FAIL  $n  $d\n"; }
}

function login(string $email, string $password): void {
    global $base;
    $land = curl($base . '/public/');
    if (!preg_match('/name="csrf-token" content="([^"]+)"/', $land['body'], $m)) {
        echo "cannot scrape CSRF\n"; exit(1);
    }
    curl($base . '/auth/login.php', [
        'email' => $email, 'password' => $password, '_csrf_token' => $m[1],
    ]);
}

// ── admin ─────────────────────────────────────────────────
echo "\n== admin (pentadbir) ==\n";
login('admin@uthm.edu.my', 'password123');
$admin = curl($base . '/dashboard/profile.php');
check('admin profile.php returns 200', $admin['code'] === 200, "code={$admin['code']}");
check('admin: no "Fakulti" info-label',
      !preg_match('#<div class="info-label">Fakulti</div>#', $admin['body']));
check('admin: no edit-faculty dropdown',
      !preg_match('#id="edit-faculty"#', $admin['body']));
check('admin: card-sub says "Tukar nama penuh anda."',
      str_contains($admin['body'], 'Tukar nama penuh anda.'));
check('admin: Nama Penuh input still present',
      str_contains($admin['body'], 'id="edit-name"'));
check('admin: Emel disabled field still present',
      str_contains($admin['body'], 'Emel (tidak boleh ditukar)'));
check('admin: no "Pilih Fakulti" option anywhere',
      !str_contains($admin['body'], 'Pilih Fakulti'));
curl($base . '/auth/logout.php');
@unlink($jar);

// ── seller (penjual) ──────────────────────────────────────
echo "\n== haziq (penjual) ==\n";
login('haziq@student.uthm.edu.my', 'password123');
$seller = curl($base . '/dashboard/profile.php');
check('seller profile.php returns 200', $seller['code'] === 200);
check('seller: "Fakulti" info-label present',
      (bool) preg_match('#<div class="info-label">Fakulti</div>#', $seller['body']));
check('seller: edit-faculty dropdown present',
      str_contains($seller['body'], 'id="edit-faculty"'));
check('seller: card-sub still mentions "fakulti"',
      str_contains($seller['body'], 'Tukar nama penuh atau fakulti anda.'));
check('seller: "Pilih Fakulti" default option visible',
      str_contains($seller['body'], 'Pilih Fakulti'));
curl($base . '/auth/logout.php');
@unlink($jar);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
