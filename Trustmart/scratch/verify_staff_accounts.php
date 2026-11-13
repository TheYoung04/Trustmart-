<?php
/**
 * scratch/verify_staff_accounts.php — "Staf UTHM" account type, end to end.
 *
 * Why this exists: the migration and the display sweep can both look fine in
 * the database while a staff account still renders a blank identifier on some
 * page, exactly the way the cash-receipt button was unreachable for all the
 * endpoint tests that passed. So this guard registers throwaway accounts
 * through the real HTTP form, activates and logs in as one, and reads the
 * rendered pages and the admin AJAX payloads back.
 *
 * Checks:
 *   A schema      — user_type + staff_no exist, matric_no is nullable
 *   B backfill    — PTU's admin is a staff account, no fake matric anywhere
 *   C signup rules— type and email domain must agree, both ways; student path
 *   D stored shape— staff account: staff_no set, matric_no NULL
 *   E duplicates  — staff_no collides as 409 and says "pegawai"
 *   F/G render    — buyer, seller and profile pages show the staff number
 *   H admin lists — every AJAX row carries a non-empty identifier
 *   I cleanup     — throwaway rows are gone, other rows untouched
 *
 * Creates only accounts named tmguard_*@…uthm.edu.my and deletes them, with
 * their trust_profiles rows, before exiting. Run:
 *   C:\xampp\php\php.exe scratch/verify_staff_accounts.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only.\n"); }

$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, $d = '') {
    global $pass, $fail;
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($c || $d === '' ? "" : "  ($d)") . "\n";
    $c ? $pass++ : $fail++;
}

$jar = sys_get_temp_dir() . '/tm_staff_' . getmypid() . '.txt'; @unlink($jar);
function http($url, $post = null, $accept_json = false) {
    global $jar;
    $ch = curl_init($url);
    $hdr = [];
    if ($accept_json) $hdr[] = 'Accept: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => $hdr]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $b = curl_exec($ch);
    if ($b === false) $b = '';
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, (string)$b];
}
function json_of($body) { $j = json_decode($body, true); return is_array($j) ? $j : []; }
// The activation link register.php returns is app-root relative (/Trustmart/auth/…).
// Attaching it to $base, which already contains /Trustmart, produced a doubled
// path, so the account was never activated and every check after that ran
// against a login page. Origin only, always.
$ORIGIN = (string)parse_url($base, PHP_URL_SCHEME) . '://' . (string)parse_url($base, PHP_URL_HOST)
        . ((int)parse_url($base, PHP_URL_PORT) ? ':' . (string)(int)parse_url($base, PHP_URL_PORT) : '');
function absolute($url) {
    global $ORIGIN;
    return preg_match('#^https?://#i', $url) ? $url : $ORIGIN . '/' . ltrim($url, '/');
}
// Fresh cookie jar, so the login-attempt counter in the session cannot throttle
// the several logins this guard legitimately makes.
function login_as($base, $email, $pw) {
    global $jar;
    @unlink($jar);
    [, $pg] = http("$base/index.php");
    $tok = token_from($pg);
    [$c, $b] = http("$base/auth/login.php", ['email' => $email, 'password' => $pw, '_csrf_token' => $tok], true);
    return $c === 200 && !empty(json_of($b)['success']);
}
function token_from($html) {
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m)) return $m[1];
    if (preg_match('/name="_csrf_token" value="([^"]+)"/', $html, $m)) return $m[1];
    return '';
}
function leaks($html) {
    $hits = [];
    foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error:', 'Parse error:',
              'Uncaught', 'SQLSTATE[', 'Undefined array key', 'undefined variable'] as $n) {
        if (stripos($html, $n) !== false) $hits[] = $n;
    }
    return $hits;
}

echo "Staff account type check — target: $base\n";

// Throwaway accounts, removed below and (defensively) before we start, so a run
// killed midway cannot leave a row that trips the unique indexes next time.
$u    = substr((string)microtime(true), -7); $u = str_replace('.', '', $u);
$staffEmail = "tmguard_staff{$u}@uthm.edu.my";
$staffNo    = "PTU-GUARD-{$u}";
$stuEmail   = "tmguard_stu{$u}@student.uthm.edu.my";
$stuMatric  = "DI" . random_int(100000, 999999);
$pw = 'guardpass123';

function purge(PDO $pdo, array $emails): void {
    $in = implode(',', array_fill(0, count($emails), '?'));
    $ids = $pdo->prepare("SELECT user_id FROM users WHERE email IN ($in)");
    $ids->execute($emails);
    $uid = array_column($ids->fetchAll(), 'user_id');
    if (!$uid) return;
    $ph = implode(',', array_fill(0, count($uid), '?'));
    foreach (['trust_profiles', 'trust_score_components', 'trust_ai_signals'] as $tbl) {
        try { $pdo->prepare("DELETE FROM {$tbl} WHERE user_id IN ($ph)")->execute($uid); }
        catch (PDOException $e) { /* table/column may not apply; the user row is what matters */ }
    }
    $pdo->prepare("DELETE FROM users WHERE user_id IN ($ph)")->execute($uid);
}
purge($pdo, [$staffEmail, $stuEmail]);

$users_before = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

// ── A. Schema ─────────────────────────────────────────────────
$col = fn($name) => $pdo->query(
    "SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = " . $pdo->quote($name)
)->fetch(PDO::FETCH_ASSOC);
$ct = (string)($col('user_type')['COLUMN_TYPE'] ?? '');
check('users.user_type exists as enum(pelajar, staf)', $ct === "enum('pelajar','staf')", $ct);
check('users.staff_no exists and is nullable',
    ($col('staff_no')['IS_NULLABLE'] ?? 'NO') === 'YES', 'staff_no column missing?');
check('users.matric_no is nullable (a staff account has none)',
    ($col('matric_no')['IS_NULLABLE'] ?? 'NO') === 'YES', 'still NOT NULL — v14 not applied');

// ── B. Backfill truth ─────────────────────────────────────────
$admin = $pdo->query("SELECT user_type, matric_no, staff_no FROM users WHERE role='pentadbir' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($admin) {
    check("the PTU admin row was relabelled honestly (staff_no=\"" . $admin['staff_no'] . "\")",
        $admin['user_type'] === 'staf' && $admin['staff_no'] !== null && $admin['matric_no'] === null,
        'user_type=' . $admin['user_type'] . ' matric_no=' . var_export($admin['matric_no'], true));
} else {
    echo "  [INFO] no pentadbir row — backfill check skipped\n";
}
$bad = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE user_type='pelajar'
                          AND (matric_no IS NULL OR matric_no='')")->fetchColumn();
check('no student-typed account is missing a matric number', $bad === 0, "$bad rows");
$both = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE matric_no IS NOT NULL
                          AND staff_no IS NOT NULL")->fetchColumn();
check('no account carries both identifiers', $both === 0, "$both rows");

// ── C. Signup rules, through the real form ────────────────────
[$c, $page] = http("$base/index.php");
$tok = token_from($page);
check('register page reachable and carries a csrf token', $c === 200 && $tok !== '', "http=$c");

function register($base, $tok, array $fields) {
    $post = array_merge(['_csrf_token' => $tok, 'password2' => $fields['password'] ?? $GLOBALS['pw'],
                         'role' => 'penjual', 'faculty' => ''], $fields);
    return http("$base/auth/register.php", $post, true);
}

[$c, $b] = register($base, $tok, ['full_name' => 'Guard Salah Domain', 'user_type' => 'staf',
    'staff_no' => $staffNo, 'email' => "tmguard_wrong{$u}@student.uthm.edu.my", 'password' => $pw]);
$j = json_of($b);
check('staf + a student mailbox is rejected', $c === 422 && (bool)preg_grep('/emel staf/i', $j['errors'] ?? []), "http=$c");

[$c, $b] = register($base, $tok, ['full_name' => 'Guard Pelajar Salah Domain', 'user_type' => 'pelajar',
    'matric_no' => $stuMatric, 'email' => "tmguard_wrong{$u}@uthm.edu.my", 'password' => $pw, 'faculty' => 'FKEE']);
$j = json_of($b);
check('pelajar + a staff mailbox is rejected', $c === 422 && (bool)preg_grep('/untuk staf/i', $j['errors'] ?? []), "http=$c");

[$c, $b] = register($base, $tok, ['full_name' => 'Guard Kosong', 'user_type' => 'staf',
    'staff_no' => '', 'email' => $staffEmail, 'password' => $pw, 'faculty' => 'PTU']);
check('staf without a staff number is rejected', $c === 422, "http=$c");

[$c, $b] = register($base, $tok, ['full_name' => 'Guard Format', 'user_type' => 'staf',
    'staff_no' => '!!', 'email' => $staffEmail, 'password' => $pw, 'faculty' => 'PTU']);
check('an unusable staff number format is rejected', $c === 422, "http=$c");

[$c, $b] = register($base, $tok, ['full_name' => 'Guard Pelajar', 'user_type' => 'pelajar',
    'matric_no' => $stuMatric, 'email' => $stuEmail, 'password' => $pw, 'faculty' => 'FKEE']);
$j = json_of($b);
check('the student path is unchanged (pelajar + student email + matric = 201)', $c === 201 && !empty($j['success']), "http=$c " . substr($b, 0, 120));
$stuLink = (string)($j['activation_link'] ?? '');

[$c, $b] = register($base, $tok, ['full_name' => 'Guard Staf', 'user_type' => 'staf',
    'staff_no' => $staffNo, 'email' => $staffEmail, 'password' => $pw, 'faculty' => 'PTU']);
$j = json_of($b);
check('staf + staff email + staff number registers (201)', $c === 201 && !empty($j['success']), "http=$c " . substr($b, 0, 160));
$actLink = (string)($j['activation_link'] ?? '');
check('activation link returned (dev mode + loopback)', $actLink !== '', 'no link — TRUSTMART_DEV_MODE off?');

// ── D. Stored shape ──────────────────────────────────────────
$row = $pdo->prepare("SELECT user_id, user_type, matric_no, staff_no, role, faculty, is_active
                        FROM users WHERE email = ? LIMIT 1");
$row->execute([$staffEmail]);
$s = $row->fetch(PDO::FETCH_ASSOC);
check('the staff account exists', (bool)$s);
if ($s) {
    $uid = (int)$s['user_id'];
    check('stored as user_type=staf with role=' . $s['role'], $s['user_type'] === 'staf');
    check('staff_no holds the submitted number', $s['staff_no'] === $staffNo, var_export($s['staff_no'], true));
    check('matric_no is NULL — no invented matric number', $s['matric_no'] === null, var_export($s['matric_no'], true));
    check('faculty PTU accepted for a staff account', $s['faculty'] === 'PTU', var_export($s['faculty'], true));

    // ── E. Duplicate staff number ────────────────────────────
    [$c, $b] = register($base, $tok, ['full_name' => 'Guard Staf Duplication', 'user_type' => 'staf',
        'staff_no' => $staffNo, 'email' => "tmguard_dup{$u}@uthm.edu.my", 'password' => $pw, 'faculty' => 'PTU']);
    $j = json_of($b);
    check('a repeated staff number is refused as 409', $c === 409, "http=$c");
    check('and the message says "pegawai", not "matrik"',
        str_contains((string)($j['message'] ?? ''), 'pegawai'), (string)($j['message'] ?? ''));

    // ── F. Activate + log in as the staff member ─────────────
    [$c, $b] = http(absolute($actLink !== '' ? $actLink : 'auth/activate.php?token='));
    check('activation link served HTTP 200', $c === 200, "http=$c url=" . absolute($actLink));
    // The student comparison account has to be activated too, or its profile
    // request silently lands on the login page.
    if ($stuLink !== '') http(absolute($stuLink));
    $row->execute([$staffEmail]);
    $s = $row->fetch(PDO::FETCH_ASSOC);
    $activated = (int)$s['is_active'] === 1;
    check('account is active after the activation link', $activated, 'is_active=' . $s['is_active']);
    $canLogin = login_as($base, $staffEmail, $pw);
    check('staff account can log in', $canLogin);

    // Everything below reads pages that only exist for a logged-in account.
    // Without this gate a failed login silently turned them into checks on the
    // login page, which contains "Staf UTHM" and "No. Matrik" in its register
    // form and therefore passes them vacuously.
    if (!$canLogin) {
        echo "\n  [INFO] staff session unavailable — render and console checks skipped\n\n";
    } else {
    // ── G. Rendered pages ────────────────────────────────────
    foreach (['dashboard/buyer.php' => 'id="tab-explore"', 'dashboard/seller.php' => 'id="tab-produk"'] as $page_ => $marker) {
        login_as($base, $staffEmail, $pw);
        [$c, $pg] = http("$base/$page_");
        check("$page_ really rendered (not a login redirect)", $c === 200 && str_contains($pg, $marker), "http=$c");
        check("$page_ shows the staff number", str_contains($pg, $staffNo), 'identifier missing');
        check("$page_ shows no blank where an identifier belongs",
            !preg_match('/(Penjual|Pembeli)( · Staf)? · (\s*<|<\/)/', $pg));
        check("$page_ is marked as a staff account", str_contains($pg, 'Staf'), 'no "Staf" wording');
        check("$page_ leaks no PHP diagnostic", !leaks($pg), implode(', ', leaks($pg)));
    }
    
    login_as($base, $staffEmail, $pw);
    [$c, $pg] = http("$base/dashboard/profile.php");
    check('profile page really rendered', $c === 200 && str_contains($pg, 'Profil Saya'), "http=$c");
    check('profile shows "Jenis Akaun: Staf UTHM"',
        (bool)preg_match('/Jenis Akaun.*?Staf UTHM/s', preg_replace('/\s+/', ' ', $pg)));
    check('profile labels the number "No. Pegawai"', str_contains($pg, 'No. Pegawai'));
    check('profile does not call it "No. Matrik"', !str_contains($pg, '>No. Matrik<'), 'label still says matrik');
    check('profile shows the staff number', str_contains($pg, $staffNo));
    // Staff are attached to a centre, not a faculty. Both the read-only row and
    // the edit form have to say so, or the register form's "Fakulti / Pusat"
    // wording dies after signup and PTU reads as a faculty.
    check('profile faculty row is labelled "Fakulti / Pusat"',
        (bool)preg_match('/info-label">Fakulti \/ Pusat</', $pg), 'still says Fakulti');
    check('edit form keeps the same wording',
        (bool)preg_match('/form-label">Fakulti \/ Pusat</', $pg), 'edit label still says Fakulti');
    check('edit form offers PTU to a staff account',
        (bool)preg_match('/id="edit-faculty"[\s\S]{0,700}?value="PTU"/', $pg), 'PTU option missing');
    check('profile leaks no PHP diagnostic', !leaks($pg), implode(', ', leaks($pg)));
    
    // A student must still see the old labels: the type-aware wording is additive.
    $stuOk = login_as($base, $stuEmail, $pw);
    check('student account can log in', $stuOk);
    if ($stuOk) {
        [$c, $pg] = http("$base/dashboard/profile.php");
        check('student profile page really rendered', $c === 200 && str_contains($pg, 'Profil Saya'), "http=$c");
        check('a student profile still says "No. Matrik" and shows the matric',
            str_contains($pg, '>No. Matrik<') && str_contains($pg, $stuMatric), "http=$c");
        check('a student profile says "Pelajar UTHM"', str_contains($pg, 'Pelajar UTHM'));
        check('a student profile has no "No. Pegawai" label', !str_contains($pg, 'No. Pegawai'));
        check('a student edit form does not offer PTU',
            !preg_match('/<option value="PTU"/', $pg), 'a student could claim a staff centre');
        // Exact label markup, not a substring: '>Fakulti<' also matches the
        // 'Pilih Fakulti' placeholder option, which would pass this vacuously.
        check('a student profile still says plain "Fakulti"',
            !str_contains($pg, 'Fakulti / Pusat')
            && (bool)preg_match('/(info-label|form-label)">Fakulti</', $pg));

        // The dropdown hiding it is not the enforcement — the endpoint has to
        // refuse it too. Submitted against this throwaway student, whose stored
        // faculty is FKEE: the endpoint keeps the stored value, so the row is
        // unchanged either way and no cleanup is needed.
        [, $tokBody] = http("$base/dashboard/profile.php");
        $stok = token_from($tokBody);
        [$c, $b] = http("$base/dashboard/profile.php?action=update_profile",
            ['full_name' => 'Guard Pelajar', 'faculty' => 'PTU', '_csrf_token' => $stok], true);
        check('a student posting faculty=PTU gets a normal response, not a silent write',
            $c === 200 && !empty(json_of($b)['success']), "http=$c");
        $fac = $pdo->prepare("SELECT faculty FROM users WHERE email = ?");
        $fac->execute([$stuEmail]);
        $fac_now = $fac->fetchColumn();
        check('and the student is still FKEE — PTU was not accepted', $fac_now === 'FKEE',
            'faculty=' . var_export($fac_now, true));
    }

    // ── H. Admin payloads ────────────────────────────────────
    check('admin login for the console checks', login_as($base, 'admin@uthm.edu.my', 'password123'));

    [$c, $b] = http("$base/dashboard/admin.php?action=get_users&search=" . urlencode($staffNo), null, true);
    $j = json_of($b);
    $hit = $j['users'][0] ?? null;
    check('admin can find the staff account by its staff number',
        $c === 200 && $hit && $hit['ident_no'] === $staffNo && $hit['user_type'] === 'staf',
        'search returned ' . count($j['users'] ?? []) . ' rows');

    foreach (['get_users' => 'users', 'get_transactions' => 'transactions',
              'get_disputes' => 'disputes', 'get_sellers' => 'sellers', 'get_ai_risks' => 'rows'] as $act => $key) {
        [$c, $b] = http("$base/dashboard/admin.php?action={$act}", null, true);
        $j = json_of($b);
        $rows = $j[$key] ?? [];
        check("admin $act returns HTTP 200 with rows", $c === 200 && is_array($rows), "http=$c");
        $missing = [];
        foreach ($rows as $r) {
            foreach (['ident_no', 'buyer_ident', 'seller_ident'] as $f) {
                if (!array_key_exists($f, $r)) continue;
                if ($r[$f] === null || $r[$f] === '') $missing[] = $f;
            }
        }
        check("admin $act has no empty identifier", !$missing, implode(',', array_unique($missing)));
    }
    [$c, $b] = http("$base/dashboard/admin.php?action=get_disputes", null, true);
    check('the dispute list still carries both parties (modal reads them)',
        $c === 200 && (count(json_of($b)['disputes'] ?? []) === 0
            || (array_key_exists('buyer_ident', json_of($b)['disputes'][0])
                && array_key_exists('seller_ident', json_of($b)['disputes'][0]))));
    }

    // ── I. Cleanup ───────────────────────────────────────────
    purge($pdo, [$staffEmail, $stuEmail, "tmguard_dup{$u}@uthm.edu.my", "tmguard_wrong{$u}@uthm.edu.my"]);
}

$left = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'tmguard_%'")->fetchColumn();
check('no throwaway account survives the run', $left === 0, "$left rows left");
$users_after = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
check('no pre-existing user row was lost', $users_after === $users_before,
    "before=$users_before after=$users_after");

echo "==============================\n";
echo "PASS: $pass  FAIL: $fail\n";
@unlink($jar);
exit($fail === 0 ? 0 : 1);
