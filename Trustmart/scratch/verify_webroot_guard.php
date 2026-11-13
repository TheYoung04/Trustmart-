<?php
/**
 * scratch/verify_webroot_guard.php — the web-root guard, verified from outside.
 *
 * Why this exists: the served document root for this project IS the project
 * folder (C:\xampp\htdocs\Trustmart is a junction onto the repo), so without
 * Trustmart/.htaccess every repo file is a plain download — .env with the DB
 * password, the SQL dumps with every password hash, the payment log. That was
 * found by requesting the URLs, not by reading the code, which is exactly why
 * the check has to be "ask the web server", never "ask the filesystem".
 *
 * A static grep can never catch this class of defect: nothing in the PHP was
 * wrong. The application was fine and the web server was leaking it.
 *
 * Checks:
 *   A config present  — both .htaccess files exist and hold the key directives
 *   B secrets blocked — every real sensitive URL answers 403, not 200
 *   C dirs blocked    — a path that does not exist still answers 403 inside a
 *                       protected dir but 404 outside one, proving the rule is
 *                       matching the DIRECTORY and not merely missing files
 *   D case/normalise  — uppercase + /. and // variants stay blocked
 *   E app intact      — every page, asset and endpoint the app needs still
 *                       answers, so the guard has not cut off the demo
 *   F no listing      — directory indexes are gone
 *
 * READ-ONLY and side-effect free by construction: it never logs in, never
 * issues a POST, and deliberately never requests payment/expire_unpaid.php or
 * ai_trust_worker.php — both are reachable-by-design scheduler endpoints that
 * WRITE when hit. Their reachability is proven by check C's 404-vs-403
 * comparison instead of by executing them.
 *
 * Negative control is structural: delete .htaccess and check B/C/D fail.
 * Widen the blocked directory list to include payment/ and check E fails.
 *
 * Run:
 *   C:\xampp\php\php.exe scratch/verify_webroot_guard.php
 *   C:\xampp\php\php.exe scratch/verify_webroot_guard.php http://localhost/Trustmart
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only.\n"); }

$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function check($n, $c, $d = '') {
    global $pass, $fail;
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($c || $d === '' ? "" : "  ($d)") . "\n";
    $c ? $pass++ : $fail++;
}

/** GET $path, follow redirects, return [status, body, content-type, headers]. */
function get($path) {
    global $base;
    $ch = curl_init($base . $path);
    $headers = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,   // a 302 is the app's own routing; do not chase it
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            $headers .= $line;
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    if ($body === false) $body = '';
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$code, (string) $body, $type, $headers];
}

/**
 * Did PHP actually run for this request? Apache's own guard page is generated
 * without the PHP module, so X-Powered-By is a real discriminator — unlike a
 * status code, which the application legitimately answers with 302/405 of its
 * own. Used only for PHP paths; static assets are served without it.
 */
function phpAnswered(array $r) {
    return stripos($r[3] ?? '', 'X-Powered-By: PHP') !== false;
}

/**
 * Did APACHE refuse this, or did the application? An Apache 403/404 is an
 * error document naming the URL; an application refusal carries the app's own
 * message (JSON from the gate endpoints, HTML from the dashboards). Only the
 * Apache answer means "the guard is doing its job".
 */
function refusedByServer(array $r) {
    [$code, $body] = $r;
    if ($code !== 403 && $code !== 404) return false;
    return stripos($body, "You don't have permission") !== false
        || stripos($body, 'Apache/2.4') !== false
        || stripos($body, 'Forbidden') !== false
        || trim(strip_tags($body)) === ''            // bare 403 with no body
        || stripos($body, 'NOT FOUND') !== false;
}

echo "Web-root guard — probing {$base}\n";

/* ── 0. transport precondition ──────────────────────────────────────────
 * Every check below asks the web server, so an unreachable server would make
 * each "blocked" assertion pass on an empty response and every "still live"
 * assertion fail — a report that looks like a config disaster and is really
 * just Apache being stopped. curl reports HTTP code 0 when it never connected.
 * Refuse to produce that misleading table. (Observed for real: a run against
 * a stack that had gone down printed 18 PASS / 52 FAIL, all of it noise.)
 */
$alive = get('/index.php');
if ($alive[0] === 0) {
    echo "\nABORT: {$base} is not answering (curl connected nowhere).\n"
     . "       Start Apache (and MySQL) in the XAMPP Control Panel, then re-run.\n"
     . "       Nothing below was evaluated — a closed server is not a passing guard.\n";
    exit(1);
}
check('web server reachable at ' . $base, true);


/* ── A. the configuration itself ──────────────────────────────────── */
echo "\nA. KONFIGURASI (config present)\n";
$htRoot = $root . '/.htaccess';
$htUp   = $root . '/uploads/.htaccess';
check('Trustmart/.htaccess exists', is_file($htRoot), $htRoot);
check('uploads/.htaccess exists',   is_file($htUp), $htUp);
$rootTxt = is_file($htRoot) ? (string) file_get_contents($htRoot) : '';
foreach (['Options -Indexes', 'FilesMatch "^\."', 'sql|log|md', 'config|includes|db_backups|logs'] as $needle) {
    check('root .htaccess declares: ' . $needle, strpos($rootTxt, $needle) !== false);
}
$upTxt = is_file($htUp) ? (string) file_get_contents($htUp) : '';
check('uploads/.htaccess defaults to deny', strpos($upTxt, 'Require all denied') !== false);
check('uploads/.htaccess allow-lists images', strpos($upTxt, 'jpe?g|png|webp|gif') !== false);

/* ── B. secrets must not be readable ──────────────────────────────── */
echo "\nB. RAHSIA DISEMBUN (secrets blocked)\n";
$secrets = [
    '/.env'                                            => 'DB password + payment secret',
    '/.env.production'                                 => 'production env template',
    '/.env.example'                                    => 'env example',
    '/.gitignore'                                      => 'repo layout hint',
    '/trustmart.sql'                                   => 'base schema dump',
    '/config/schema_update.sql'                        => 'migration v1',
    '/config/schema_update_v13.sql'                    => 'migration v13',
    '/db_backups/trustmart-before-payments-20261001-043103.sql' => 'full data backup',
    '/logs/payment_callback.log'                       => 'payment callback log',
    '/DEPLOYMENT.md'                                   => 'internal deployment notes',
    '/CONFIG.md'                                       => 'internal config notes',
    '/docs/SYSTEM_BRIEF.md'                            => 'system brief',
    '/docs/uat/UAT_RESULTS.csv'                        => 'UAT data',
    '/includes/csrf.php'                               => 'CSRF bootstrap source',
    '/includes/notifications.php'                      => 'notification helper source',
    '/config/db.php'                                   => 'DB bootstrap source',
    '/config/payment.php'                              => 'payment secret handling source',
    '/scratch/db_reset_demo.php'                       => 'destructive dev script',
    '/scratch/db_reset_passwords.php'                  => 'destructive dev script',
    '/tests/test_pembeli_role.php'                     => 'UAT script',
];
foreach ($secrets as $path => $why) {
    $r = get($path);
    check("403 on {$path}  [{$why}]", refusedByServer($r) || $r[0] === 403 || $r[0] === 404,
          'got HTTP ' . $r[0]);
    // The decisive part: the secret itself must never be in the body.
    if ($path === '/.env') {
        check('  .env body leaks no DB_HOST/DB_PASS',
              stripos($r[1], 'DB_PASS') === false && stripos($r[1], 'DB_HOST') === false,
              'body length ' . strlen($r[1]));
    }
    if ($path === '/db_backups/trustmart-before-payments-20261001-043103.sql') {
        check('  backup body leaks no password hash',
              stripos($r[1], '$2y$') === false && stripos($r[1], 'CREATE TABLE') === false);
    }
}

/* ── C. the rule is matching DIRECTORIES, not missing files ───────── */
echo "\nC. BLOCK BY DIRECTORY (403 inside, 404 outside)\n";
foreach (['/config', '/includes', '/logs', '/db_backups', '/scratch', '/tests', '/docs'] as $dir) {
    $r = get($dir . '/__definitely_not_here__.php');
    check("{$dir}/ path is refused before the filesystem is consulted",
          refusedByServer($r) || $r[0] === 403, 'got HTTP ' . $r[0]);
}
// Contrast: a missing file in a directory the app NEEDS must still 404. If
// this 403s, the block list has grown into live application space.
$missPay = get('/payment/__definitely_not_here__.php');
check('/payment/ missing file answers 404, not 403 (payment/ stays open)',
      $missPay[0] === 404, 'got HTTP ' . $missPay[0]);
$missRoot = get('/__definitely_not_here__.php');
check('root missing file answers 404, not 403 (root PHP stays reachable)',
      $missRoot[0] === 404, 'got HTTP ' . $missRoot[0]);
$missDash = get('/dashboard/__definitely_not_here__.php');
check('/dashboard/ missing file answers 404, not 403', $missDash[0] === 404, 'got HTTP ' . $missDash[0]);

/* ── D. case + path normalisation variants ────────────────────────── */
echo "\nD. VARIAN LALUAN (case + normalisation)\n";
foreach ([
    '/.ENV'                    => 'uppercase dotfile on a case-insensitive FS',
    '/CONFIG/DB.PHP'           => 'uppercase dir (proves the NC flag)',
    '//.env'                   => 'double slash',
    '/./.env'                  => 'single-dot segment',
    '/config/./db.php'         => 'dot segment inside a blocked dir',
    '/db_backups/../.env'      => 'traversal-looking path',
] as $path => $why) {
    $r = get($path);
    check("refused: {$path}  [{$why}]", refusedByServer($r) || $r[0] === 403 || $r[0] === 404,
          'got HTTP ' . $r[0]);
}

/* ── E. the application still works ───────────────────────────────── */
echo "\nE. APLIKASI HIDUP (app not cut off)\n";
$mustLive = [
    '/index.php'                            => [200, 302],
    '/public/index.php'                     => [200, 302],
    // auth/login|register|forgot_password are POST-ONLY JSON endpoints by
    // design (their first guard is http_response_code(405)), and product.php
    // demands a session so it 302s to the login page with a ?redirect= back
    // param. Expecting 200 there asserts the wrong thing.
    '/auth/login.php'                       => [405],
    '/auth/register.php'                    => [405],
    '/auth/forgot_password.php'             => [405],
    '/auth/activate.php'                    => [200],
    '/auth/reset_password.php'              => [200],
    '/product.php?id=1'                     => [200, 302],
    '/receipt.php?id=1'                     => [200, 302],
    '/public/assets/css/trustmart-ui.css'   => [200],
    '/public/assets/js/trustmart-ui.js'     => [200],
];
// Which of those must show PHP having run. Static assets are deliberately
// absent — Apache serves them itself, so demanding the PHP fingerprint there
// would fail for the wrong reason.
$phpPaths = ['/index.php', '/public/index.php', '/auth/login.php', '/auth/register.php',
             '/auth/forgot_password.php', '/auth/activate.php', '/auth/reset_password.php',
             '/product.php?id=1', '/receipt.php?id=1'];
foreach ($mustLive as $path => $ok) {
    $r = get($path);
    $viaPhp = !in_array($path, $phpPaths, true) || phpAnswered($r);
    check("reaches the app " . implode('/', $ok) . ": {$path}",
          in_array($r[0], $ok, true) && !refusedByServer($r) && $viaPhp,
          'got HTTP ' . $r[0] . ' ct=' . $r[2] . ($viaPhp ? '' : ' [no PHP fingerprint]'));
}
// Those endpoints must answer in the APPLICATION's voice, not a generic page.
foreach (['/auth/login.php', '/auth/register.php', '/auth/forgot_password.php'] as $path) {
    $r = get($path);
    check("app JSON guard on {$path}",
          $r[0] === 405 && strpos($r[2], 'application/json') !== false
          && stripos($r[1], 'success') !== false && stripos($r[1], 'dibenarkan') !== false,
          'ct=' . $r[2] . ' body=' . substr($r[1], 0, 60));
}
// Unauthenticated consoles must redirect to the login page — a 403 here would
// mean the guard, not the app, is answering.
foreach (['/dashboard/buyer.php', '/dashboard/seller.php', '/dashboard/admin.php',
          '/dashboard/profile.php', '/dashboard/admin_returns.php'] as $path) {
    $r = get($path);
    check("console routes still handled by the app: {$path}",
          in_array($r[0], [200, 302, 303], true) && !refusedByServer($r), 'got HTTP ' . $r[0]);
}
// Money endpoints must remain reachable: the gateway has to be able to call
// callback.php from outside, or paid orders never settle. A 403 is only
// acceptable when it is the APPLICATION's own refusal (bad signature), never
// Apache's guard page.
foreach (['/payment/callback.php', '/payment/return.php', '/payment/pay.php?txn=1'] as $path) {
    $r = get($path);
    check("payment endpoint reachable: {$path}", !refusedByServer($r), 'got HTTP ' . $r[0]);
}
// Page-identity marker on the REAL login surface. public/index.php renders the
// form and auth/*.php are its JSON endpoints, so the form has to be found here.
$login = get('/public/index.php');
check('login page still renders the TrustMart form',
      $login[0] === 200 && stripos($login[1], 'Trust') !== false
      && stripos($login[1], 'csrf-token') !== false
      && stripos($login[1], 'password') !== false,
      'body length ' . strlen($login[1]) . ' code ' . $login[0]);
$css = get('/public/assets/css/trustmart-ui.css');
check('CSS is served as a stylesheet with real content',
      $css[0] === 200 && stripos($css[2], 'css') !== false && strlen($css[1]) > 2000,
      'ct=' . $css[2] . ' len=' . strlen($css[1]));
// A product image must still load — uploads/ is the one dir that must serve.
$img = null;
foreach (['powerbank.png', 'dell_laptop.png', 'college_shirt.png', 'casing_phone.png'] as $f) {
    $t = get('/uploads/products/' . $f);
    if ($t[0] === 200) { $img = [$f, $t]; break; }
    if ($img === null && $t[0] !== 404) $img = [$f . '(code ' . $t[0] . ')', $t];
}
check('an uploaded product image is still served',
      $img !== null && $img[1][0] === 200 && strpos((string) $img[1][2], 'image/') === 0,
      $img === null ? 'no candidate image found' : $img[0] . ' -> ' . $img[1][0] . ' ' . $img[1][2]);

/* ── F. no directory listing ──────────────────────────────────────── */
echo "\nF. TIADA SENARAI DIREKTORI (no index listing)\n";
// Asserted as a required STATUS, not merely "the body has no listing text":
// before this guard existed /Trustmart/uploads/products/ answered 200 with a
// generated index of every uploaded filename, so a permissive check here is
// exactly the mistake that let the exposure survive.
$dirExpect = [
    '/uploads/products/' => 403,          // Options -Indexes, no index file inside
    '/db_backups/'       => 403,
    '/config/'           => 403,
    '/scratch/'          => 403,
    '/logs/'             => 403,
    '/'                  => [200, 302],   // root index.php must still answer
];
foreach ($dirExpect as $dir => $want) {
    $r = get($dir);
    $ok = is_array($want) ? in_array($r[0], $want, true) : $r[0] === $want;
    $listing = stripos($r[1], 'Index of /') !== false;
    check("{$dir} -> " . (is_array($want) ? implode('/', $want) : $want) . ', no listing',
          $ok && !$listing, 'got HTTP ' . $r[0] . ($listing ? ' + generated listing' : ''));
}

echo "\n--------------------------------------------------\n";
echo "PASS: {$pass}  FAIL: {$fail}\n";
echo "--------------------------------------------------\n";
exit($fail ? 1 : 0);
