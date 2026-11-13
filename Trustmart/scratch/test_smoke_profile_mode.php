<?php
// ============================================================
// scratch/test_smoke_profile_mode.php
// Verifies the "Mod Pembeli -> Profil Saya" return-link fix:
// profile.php must respect the mode the seller arrived from so
// "Kembali ke Dashboard" brings them back to the SAME page
// (buyer.php in Mod Pembeli, seller.php in Mod Penjual).
//
// Read-only: no DB mutation. Just log in as haziq and inspect
// rendered HTML.
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$base = 'http://localhost/Trustmart';
$jar  = sys_get_temp_dir() . '/tm_profile_mode_' . getmypid() . '.cookie';
@unlink($jar);

function curl(string $url, ?array $post = null, ?string $referer = null, ?string $csrf_header = null): array {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($csrf_header !== null) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-CSRF-Token: ' . $csrf_header]);
    }
    if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc  = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['code'=>$code, 'body'=>(string)$body, 'loc'=>$loc];
}

$pass = 0; $fail = 0;
function check(string $n, bool $c, string $d = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  $n\n"; }
    else    { $fail++; echo "  FAIL  $n  $d\n"; }
}

// 1) Grab CSRF from the landing page.
$land = curl($base . '/public/');
if (!preg_match('/name="csrf-token" content="([^"]+)"/', $land['body'], $m)) {
    echo "cannot scrape CSRF from landing\n"; exit(1);
}
$csrf = $m[1];

// 2) Log in as haziq (a penjual).
curl($base . '/auth/login.php', [
    'email'        => 'haziq@student.uthm.edu.my',
    'password'     => 'password123',
    '_csrf_token'  => $csrf,
]);

// 3) GET /dashboard/buyer.php (Mod Pembeli).
$buyer = curl($base . '/dashboard/buyer.php');
check('buyer.php loads (200)', $buyer['code'] === 200, 'code=' . $buyer['code']);
check(
    'Mod Pembeli Profil Saya href carries ?mode=pembeli',
    strpos($buyer['body'], 'href="profile.php?mode=pembeli"') !== false,
    'sidebar link not updated'
);

// 4) GET /dashboard/profile.php?mode=pembeli -> should return to buyer.php.
$prof = curl(
    $base . '/dashboard/profile.php?mode=pembeli',
    null,
    $base . '/dashboard/buyer.php'
);
check('profile.php loads (200)', $prof['code'] === 200, 'code=' . $prof['code']);
// The "Kembali ke Dashboard" anchor is the first nav-item in the sidebar.
$okReturn = (bool) preg_match(
    '#<a class="nav-item" href="buyer\.php"[^>]*>.*?Kembali ke Dashboard#s',
    $prof['body']
);
check('mode=pembeli => Kembali ke Dashboard goes to buyer.php', $okReturn,
      'did not find buyer.php return link');
check(
    'mode=pembeli => topbar shows "Penjual (Mod Pembeli)"',
    strpos($prof['body'], 'Penjual (Mod Pembeli)') !== false,
    'role label not switched'
);

// 5) GET /dashboard/profile.php (no mode) BUT Referer = buyer.php -> same behaviour.
$prof2 = curl(
    $base . '/dashboard/profile.php',
    null,
    $base . '/dashboard/buyer.php'
);
check(
    'Referer=buyer.php (no ?mode=) => return link still buyer.php',
    (bool) preg_match('#<a class="nav-item" href="buyer\.php"[^>]*>.*?Kembali ke Dashboard#s', $prof2['body']),
    'referer fallback not honoured'
);

// 6) GET /dashboard/profile.php?mode=penjual -> seller.php (unchanged).
$prof3 = curl(
    $base . '/dashboard/profile.php?mode=penjual',
    null,
    $base . '/dashboard/seller.php'
);
check(
    'mode=penjual => return link is seller.php',
    (bool) preg_match('#<a class="nav-item" href="seller\.php"[^>]*>.*?Kembali ke Dashboard#s', $prof3['body']),
    'seller mode regression'
);

// 7) GET /dashboard/profile.php (no mode, no referer) -> default seller.php for penjual.
$prof4 = curl($base . '/dashboard/profile.php');
check(
    'no mode / no referer => default seller.php for a penjual',
    (bool) preg_match('#<a class="nav-item" href="seller\.php"[^>]*>.*?Kembali ke Dashboard#s', $prof4['body']),
    'default changed unexpectedly'
);

@unlink($jar);
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
