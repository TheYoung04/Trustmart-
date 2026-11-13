<?php
/**
 * scratch/verify_notifications.php — the "needs your action" badge on the
 * buyer and seller consoles, checked against the database and the live HTML.
 *
 * Why a guard for a badge at all: the number is the one part of this feature a
 * supervisor can judge in a second. If it says 4 and only 2 things are actually
 * waiting on that user, the feature has taught everyone to ignore badges — the
 * exact failure the admin console avoided by counting only urgent disputes.
 * So this checks three separate things:
 *
 *   1. the maths       — helper count vs an independent recount written a
 *                        different way (complement counting, so an
 *                        over-inclusive predicate cannot agree with itself)
 *   2. the rendering   — sidebar chip, topbar pill and the quiet fallback all
 *                        carry that same number, on the real page, for a real
 *                        logged-in account
 *   3. both renderers  — the AJAX stats payload the seller console re-reads
 *                        after every order action says the same thing as the
 *                        page that was just loaded ("two renderers, one
 *                        patched" is this project's most repeated defect)
 *
 * Read-only: it never writes to the database, so which accounts it logs in as
 * is chosen from live data and cannot disturb it. The admin console is included
 * as a regression check on the "N mendesak" label, which used to print twice
 * on the same stat card.
 *
 * Run: C:\xampp\php\php.exe scratch/verify_notifications.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only.\n"); }

$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/includes/notifications.php';

$pass = 0; $fail = 0;
function check($n, $c, $d = '') {
    global $pass, $fail;
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($c || $d === '' ? "" : "  ($d)") . "\n";
    $c ? $pass++ : $fail++;
}

$jar = sys_get_temp_dir() . '/tm_notify_' . getmypid() . '.txt'; @unlink($jar);
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
function token_from($html) {
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m)) return $m[1];
    if (preg_match('/name="_csrf_token" value="([^"]+)"/', $html, $m)) return $m[1];
    return '';
}
function leaks($html) {
    $hits = [];
    foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error:', 'Parse error:',
              'Uncaught', 'SQLSTATE[', 'Undefined array key', 'undefined variable',
              'pending_actions(', 'notify_topbar_html('] as $n) {
        if (stripos($html, $n) !== false) $hits[] = $n;
    }
    return $hits;
}
// Fresh cookie jar per login: the session-side login-attempt counter would
// otherwise throttle three legitimate logins in one run.
function login_as($base, $email, $pw) {
    global $jar;
    @unlink($jar);
    [, $pg] = http("$base/index.php");
    $tok = token_from($pg);
    [$c, $b] = http("$base/auth/login.php", ['email' => $email, 'password' => $pw, '_csrf_token' => $tok], true);
    return $c === 200 && !empty(json_of($b)['success']);
}
// Pull the number out of markup the helper generated, by id — never by hoping a
// bare digit is unique on a page this dense.
function num_of($html, $id) {
    return preg_match('/id="' . preg_quote($id, '/') . '"[^>]*>\s*(\d+)\s*</', $html, $m)
        ? (int) $m[1] : null;
}
function display_of($html, $id) {
    return preg_match('/id="' . preg_quote($id, '/') . '"[^>]*display:\s*([a-z-]+)/', $html, $m)
        ? $m[1] : null;
}

/**
 * Independent recount, deliberately worded differently from pending_actions():
 * count everything in the state, then subtract what the predicate excludes.
 * If the predicate were too wide or too narrow, the two figures part.
 */
function recount(PDO $pdo, int $uid, string $side): array {
    $col = $side === 'penjual' ? 'seller_id' : 'buyer_id';

    $q = function (string $where) use ($pdo, $col, $uid) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM transactions t WHERE t.$col = ? AND $where");
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    };

    $all_wait   = $q("t.status = 'Menunggu'");
    $all_done   = $q("t.status = 'Selesai'");
    $all_ok     = $q("t.status = 'Disahkan'");
    $all_cancel = $q("t.status = 'Dibatalkan'");

    if ($side === 'penjual') {
        // Everything the seller is NOT on duty for while the row still says
        // Menunggu: unpaid online (buyer's turn), refunded, cancelled rows.
        $not_action = $q("t.status = 'Menunggu' AND NOT (
                (t.payment_type = 'online' AND t.payment_status = 'paid')
             OR (t.payment_type = 'cash'   AND t.payment_status = 'unpaid_cash'))");
        $action = $all_wait - $not_action;
    } else {
        // Menunggu rows that are not the buyer's to pay: a cash order needs no
        // gateway, and one already paid waits on the seller.
        $not_action = $q("t.status = 'Menunggu' AND NOT (
                t.payment_type = 'online'
            AND t.payment_status IN ('unpaid','pending','failed'))");
        $action = ($all_wait - $not_action) + $all_ok;
    }

    $disputes_all = (int) $pdo->query(
        "SELECT COUNT(*) FROM disputes d JOIN transactions t ON t.transaction_id = d.transaction_id
          WHERE t.$col = $uid")->fetchColumn();
    $disputes_shut = (int) $pdo->query(
        "SELECT COUNT(*) FROM disputes d JOIN transactions t ON t.transaction_id = d.transaction_id
          WHERE t.$col = $uid AND d.status = 'Selesai'")->fetchColumn();

    $reviewed = (int) $pdo->query(
        "SELECT COUNT(*) FROM transactions t WHERE t.buyer_id = $uid AND t.status = 'Selesai'
          AND EXISTS (SELECT 1 FROM reviews r WHERE r.transaction_id = t.transaction_id)")->fetchColumn();

    // Reviews are the buyer's act: a seller side never has any, so counting the
    // orders the user SOLD as if they owed reviews would disagree with the
    // helper for a reason that has nothing to do with the badge.
    return [
        'action'   => $action,
        'disputes' => $disputes_all - $disputes_shut,
        'reviews'  => $side === 'penjual' ? 0 : $all_done - $reviewed,
        'context'  => "Menunggu=$all_wait Selesai=$all_done Disahkan=$all_ok Dibatalkan=$all_cancel",
    ];
}

echo "Buyer/seller notification badge — target: $base\n";

/* ── 0. Pick real accounts from live data ──────────────────────── */
$cands = $pdo->query("SELECT user_id, email, role, password_hash FROM users WHERE is_active = 1")
             ->fetchAll(PDO::FETCH_ASSOC);
$pw_ok = function (array $r) { return password_verify('password123', (string) $r['password_hash']); };

$pick = ['penjual' => null, 'pembeli' => null, 'senyap' => null];
foreach ($cands as $r) {
    if (!$pw_ok($r)) continue;
    foreach (['penjual', 'pembeli'] as $side) {
        $n = pending_actions($pdo, (int) $r['user_id'], $side);
        $score = $n['action'] * 10 + $n['disputes'] * 3 + $n['reviews'];
        // a seller console account that also has a dispute, so the blue pill is
        // exercised on a page load rather than only in the unit checks
        if ($side === 'penjual' && $r['role'] === 'penjual' && $n['action'] > 0) {
            if (!$pick['penjual'] || $score > $pick['penjual']['score'])
                $pick['penjual'] = ['row' => $r, 'n' => $n, 'score' => $score];
        }
        // a buyer console account that has something to pay or close
        if ($side === 'pembeli' && $n['action'] > 0) {
            if (!$pick['pembeli'] || $score > $pick['pembeli']['score'])
                $pick['pembeli'] = ['row' => $r, 'n' => $n, 'score' => $score];
        }
        // and one with nothing to do but reviews: the badge must stay quiet
        if ($side === 'pembeli' && $n['action'] === 0 && $n['reviews'] > 0 && $n['disputes'] === 0
            && $r['role'] === 'pembeli' && !$pick['senyap']) {
            $pick['senyap'] = ['row' => $r, 'n' => $n, 'score' => $score];
        }
    }
}
$admin = null;
foreach ($cands as $r) {
    if ($r['role'] === 'pentadbir' && $pw_ok($r)) { $admin = $r; break; }
}

foreach (['penjual' => 'seller console', 'pembeli' => 'buyer console', 'senyap' => 'quiet buyer'] as $k => $what) {
    check("a live account exists for the $what", !empty($pick[$k]),
        'no active account with password123 matches the scenario — seed it before demoing');
}
if (empty($pick['penjual']) || empty($pick['pembeli']) || empty($pick['senyap']) || !$admin) {
    echo "\nPASS: $pass  FAIL: $fail\n";
    echo "Cannot continue without the accounts above.\n";
    exit(1);
}

/* ── 1. The maths, for every active account with transactions ──── */
echo "\n[1] Kiraan vs recounts bebas\n";
$checked_users = 0;
foreach ($cands as $r) {
    $uid = (int) $r['user_id'];
    foreach (['penjual', 'pembeli'] as $side) {
        $col2 = $side === 'penjual' ? 'seller_id' : 'buyer_id';
        $has = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE $col2 = $uid")->fetchColumn();
        if ($has === 0) continue;
        $n = pending_actions($pdo, $uid, $side);
        $x = recount($pdo, $uid, $side);
        // Only report a mismatch, so the run stays readable across ~17 accounts;
        // the tally below proves the loop actually ran.
        if ($n['action'] !== $x['action'] || $n['disputes'] !== $x['disputes'] || $n['reviews'] !== $x['reviews']) {
            check("user $uid ($side) counts match the recount", false,
                "helper action={$n['action']} disputes={$n['disputes']} reviews={$n['reviews']}"
                . " vs recount action={$x['action']} disputes={$x['disputes']} reviews={$x['reviews']}"
                . " | {$x['context']}");
        }
        $checked_users++;
    }
}
check('the recount ran over real accounts', $checked_users >= 8, "$checked_users user/side pairs checked");
check('a seller with a cash order waiting to be recorded exists',
    (bool) $pdo->query("SELECT COUNT(*) FROM transactions
                         WHERE status='Menunggu' AND payment_type='cash' AND payment_status='unpaid_cash'")->fetchColumn(),
    'the stranded-cash case is the reason the seller badge exists — seed one for the demo');
// The "belum diulas" badge is a NOT EXISTS over reviews.transaction_id. If that
// link were nullable or unfilled, every completed order would look unreviewed
// forever and the gold pill would nag without ever clearing.
$rev_nullable = $pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews'
      AND COLUMN_NAME = 'transaction_id'")->fetchColumn();
$rev_written = (int) $pdo->query("SELECT COUNT(*) FROM transactions t WHERE t.status = 'Selesai'
    AND EXISTS (SELECT 1 FROM reviews r WHERE r.transaction_id = t.transaction_id)")->fetchColumn();
$rev_open    = (int) $pdo->query("SELECT COUNT(*) FROM transactions t WHERE t.status = 'Selesai'
    AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.transaction_id = t.transaction_id)")->fetchColumn();
check('a completed order can be told apart as reviewed or not',
    $rev_nullable === 'NO' && $rev_written > 0 && $rev_open > 0,
    "nullable=$rev_nullable reviewed=$rev_written unreviewed=$rev_open");
check('an unpaid online order is NOT counted as the seller\'s action',
    (function ($pdo) {
        $s = $pdo->query("SELECT seller_id FROM transactions
                           WHERE status='Menunggu' AND payment_type='online'
                             AND payment_status IN ('unpaid','pending','failed') LIMIT 1")->fetchColumn();
        if (!$s) return true; // no such row right now; the predicate is still unit-checked below
        $n = pending_actions($pdo, (int) $s, 'penjual');
        $mine = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE seller_id=$s
                                    AND status='Menunggu' AND payment_type='online'
                                    AND payment_status IN ('unpaid','pending','failed')")->fetchColumn();
        $wait = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE seller_id=$s AND status='Menunggu'")->fetchColumn();
        return $n['action'] <= $wait - $mine;
    })($pdo),
    'a seller badge that counts orders it cannot confirm would be a lie');

/* ── 2. Unit behaviour of the renderer ─────────────────────────── */
echo "\n[2] Lelakar penghasil HTML\n";
$quiet = notify_topbar_html(['action' => 0, 'reviews' => 0, 'disputes' => 0, 'side' => 'penjual'], 'x', 'pesanan');
check('nothing pending → the red pill is hidden, not removed',
    (bool) preg_match('/id="x-action-pill"[^>]*style="display:none/', $quiet),
    'pill must stay in the DOM for the AJAX updater');
check('nothing pending → the calm wording is visible',
    (bool) preg_match('/id="x-empty"[^>]*style="display:inline/', $quiet));
check('nothing pending → no count is printed as urgent', !preg_match('/display:flex/', $quiet));
$loud = notify_topbar_html(['action' => 2, 'reviews' => 0, 'disputes' => 0, 'side' => 'penjual'], 'x', 'pesanan');
check('something pending → the red pill shows the number', (bool) preg_match('/id="x-action">2<\/span>/', $loud));
check('something pending → the calm wording is hidden',
    (bool) preg_match('/id="x-empty"[^>]*style="display:none/', $loud));
check('a pill jumps to the tab that holds the action', str_contains($loud, 'onclick="showTab(\'pesanan\')"'));
$mixed = notify_topbar_html(['action' => 1, 'reviews' => 4, 'disputes' => 3, 'side' => 'pembeli'], 'x', 'pesanan');
check('reviews get their own pill, not a bigger red number',
    (int) preg_match('/id="x-review">4<\/span>/', $mixed) && num_of($mixed, 'x-action') === 1);
check('disputes get their own pill, because PTU acts on them',
    (bool) preg_match('/id="x-dispute">3<\/span>/', $mixed));
$silent = notify_topbar_html(['action' => 1, 'reviews' => 9, 'disputes' => 0, 'side' => 'penjual'], 'x', 'pesanan');
check('the seller console never nags about reviews (a buyer does those)',
    !str_contains($silent, 'x-review'));
$chip0 = notify_count_chip_html(0, 'x-nav-count');
check('a zero sidebar chip is hidden but present',
    (bool) preg_match('/id="x-nav-count"[^>]*style="display:none/', $chip0)
    && str_contains($chip0, '></span>'), 'renderPendingNotify needs the node to write back into');
// Narrow screens: the pills share the topbar's right-hand cluster with the
// trust chip and the identity line. The shared stylesheet caps that cluster at
// 42vw for the public product page, where ellipsis is the right answer; the
// consoles must instead be allowed to wrap, or a count is shown cut mid-word.
$css = (string) file_get_contents($root . '/public/assets/css/trustmart-ui.css');
$cap    = strpos($css, 'max-width: 42vw');
$noClip = false;
if ($cap !== false && preg_match('/\.console-topbar > :last-child\s*\{([^}]*)\}/s', $css, $mb, PREG_OFFSET_CAPTURE)) {
    $body = $mb[1][0];
    // Both halves matter: the cap must be overridden (overflow) and the cluster
    // must be allowed to break onto a second row, and this rule has to sit after
    // the cap in the file so it actually wins the cascade.
    $noClip = $mb[0][1] > $cap
        && strpos($body, 'flex-wrap: wrap') !== false
        && strpos($body, 'overflow: visible') !== false;
}
check('the shared stylesheet lets the console topbar wrap instead of clipping', $noClip,
    'the wrap rule must come after the 42vw cap, or the badge is truncated on phones');
foreach (['dashboard/buyer.php', 'dashboard/seller.php'] as $page_) {
    check("$page_ opts in with class=\"topbar console-topbar\"",
        (bool) preg_match('/class="topbar console-topbar"/', (string) file_get_contents($root . '/' . $page_)));
}
check('the public product page keeps its ellipsis cap',
    !str_contains((string) file_get_contents($root . '/product.php'), 'console-topbar'),
    'a long product title must still be truncated, not allowed to push the buy button away');
$js = notify_js('x');
check('the AJAX updater is generated for the same ids the renderer emits',
    str_contains($js, "getElementById('x-nav-count')") && str_contains($js, "'x-' + key"));
check('the updater never rewrites wording from JS',
    !preg_match('/innerHTML|insertAdjacentHTML|document\.write/', $js),
    'a second copy of the label in JS is how the two renderers drift apart');

/* ── 3. The seller console, on the wire ────────────────────────── */
echo "\n[3] Dashboard penjual (" . $pick['penjual']['row']['email'] . ")\n";
$sellerRow = $pick['penjual']['row'];
$sellerN   = $pick['penjual']['n'];
$ok = login_as($base, $sellerRow['email'], 'password123');
check('seller logged in', $ok);
if ($ok) {
    [$c, $pg] = http("$base/dashboard/seller.php");
    check('seller.php rendered', $c === 200 && str_contains($pg, 'Pesanan Diterima'), "http=$c");
    check('seller.php leaks no PHP diagnostic', !leaks($pg), implode(', ', leaks($pg)));
    check('seller sidebar chip shows the helper count',
        num_of($pg, 'notif-nav-count') === $sellerN['action'],
        'chip=' . var_export(num_of($pg, 'notif-nav-count'), true) . ' helper=' . $sellerN['action']);
    check('seller topbar pill shows the same count',
        num_of($pg, 'notif-action') === $sellerN['action'], 'pill=' . var_export(num_of($pg, 'notif-action'), true));
    check('the pill is visibly rendered, not just present',
        display_of($pg, 'notif-action-pill') === 'flex');
    check('the wording is printed once per page',
        substr_count($pg, 'perlu tindakan anda') === 1, substr_count($pg, 'perlu tindakan anda') . ' copies');
    check('the calm wording is hidden while work is pending',
        display_of($pg, 'notif-empty') === 'none');
    $d = $sellerN['disputes'];
    check($d > 0 ? 'an open dispute shows as its own quieter pill' : 'no open dispute → no dispute pill',
        $d > 0 ? num_of($pg, 'notif-dispute') === $d : !str_contains($pg, 'notif-dispute'),
        "helper disputes=$d");
    // Anchored on the pill itself: the sidebar already has a showTab('pesanan'),
    // so a page-wide search would pass with no pill at all.
    check('the pill itself jumps to a tab that exists on the page',
        (bool) preg_match('/id="notif-action-pill"[^>]*onclick="showTab\(\'pesanan\'\)"/', $pg)
        && str_contains($pg, 'id="tab-pesanan"'));
    foreach (['notif-action', 'notif-action-pill', 'notif-nav-count', 'notif-empty'] as $id) {
        check("renderPendingNotify's node id=\"$id\" exists in the markup", str_contains($pg, "id=\"$id\""));
    }
    check('the number-only updater is on the page', str_contains($pg, 'function renderPendingNotify'));

    [$c, $b] = http("$base/dashboard/seller.php?action=get_stats", null, true);
    $j = json_of($b);
    $aj = $j['stats']['notify']['action'] ?? null;
    check('the AJAX re-read agrees with the loaded page', $aj === $sellerN['action'],
        "http=$c ajax=" . var_export($aj, true) . ' page=' . $sellerN['action']);
    check('the AJAX re-read agrees on disputes too',
        ($j['stats']['notify']['disputes'] ?? null) === $d, 'ajax=' . var_export($j['stats']['notify']['disputes'] ?? null, true));
}

/* ── 4. The buyer console, on the wire ─────────────────────────── */
echo "\n[4] Dashboard pembeli (" . $pick['pembeli']['row']['email'] . ")\n";
$buyerRow = $pick['pembeli']['row'];
$buyerN   = $pick['pembeli']['n'];
$ok = login_as($base, $buyerRow['email'], 'password123');
check('buyer logged in', $ok);
if ($ok) {
    [$c, $pg] = http("$base/dashboard/buyer.php");
    check('buyer.php rendered', $c === 200 && str_contains($pg, 'Jelajah Produk'), "http=$c");
    check('buyer.php leaks no PHP diagnostic', !leaks($pg), implode(', ', leaks($pg)));
    check('buyer sidebar chip shows the helper count',
        num_of($pg, 'notif-nav-count') === $buyerN['action'],
        'chip=' . var_export(num_of($pg, 'notif-nav-count'), true) . ' helper=' . $buyerN['action']);
    check('buyer topbar pill shows the same count', num_of($pg, 'notif-action') === $buyerN['action']);
    check('the buyer count is the buyer\'s own, not the seller\'s',
        $buyerN['detail']['perlu_bayar'] + $buyerN['detail']['perlu_selesai'] === $buyerN['action']
        && !isset($buyerN['detail']['perlu_sahkan']));
    check('the wording is printed once per page', substr_count($pg, 'perlu tindakan anda') === 1);
    check('the buyer pill itself jumps to a tab that exists',
        (bool) preg_match('/id="notif-action-pill"[^>]*onclick="showTab\(\'pesanan\'\)"/', $pg)
        && str_contains($pg, 'id="tab-pesanan"'));
    if ($buyerN['disputes'] > 0) {
        check('a dispute the buyer is party to is reported apart from the red number',
            num_of($pg, 'notif-dispute') === $buyerN['disputes'] && num_of($pg, 'notif-action') === $buyerN['action']);
    }
}

/* ── 5. A buyer with nothing to do must not be nagged ──────────── */
echo "\n[5] Pembeli tanpa tindakan (" . $pick['senyap']['row']['email'] . ")\n";
$quietRow = $pick['senyap']['row'];
$quietN   = pending_actions($pdo, (int) $quietRow['user_id'], 'pembeli');
$ok = login_as($base, $quietRow['email'], 'password123');
check('quiet buyer logged in', $ok);
if ($ok) {
    [$c, $pg] = http("$base/dashboard/buyer.php");
    check('buyer.php rendered for the quiet account', $c === 200 && str_contains($pg, 'Jelajah Produk'), "http=$c");
    check('no red pill is shown when nothing is pending',
        display_of($pg, 'notif-action-pill') === 'none', 'display=' . var_export(display_of($pg, 'notif-action-pill'), true));
    check('the sidebar chip is hidden, not left showing a stale zero',
        display_of($pg, 'notif-nav-count') === 'none');
    check('the calm wording is shown instead', str_contains($pg, 'Tiada pesanan menunggu tindakan anda'));
    check('the review invitations still show, in the quiet colour',
        $quietN['reviews'] > 0 && num_of($pg, 'notif-review') === $quietN['reviews'],
        'helper reviews=' . $quietN['reviews']);
    check('reviews are never counted inside the red number',
        num_of($pg, 'notif-action') === 0 || display_of($pg, 'notif-action-pill') === 'none');
}

/* ── 6. The admin console is unchanged in shape ───────────────── */
echo "\n[6] Dashboard pentadbir (regresi label)\n";
check('admin logged in', login_as($base, $admin['email'], 'password123'));
[$c, $pg] = http("$base/dashboard/admin.php");
check('admin.php rendered', $c === 200 && str_contains($pg, 'Urus Aduan'), "http=$c");
check('admin.php leaks no PHP diagnostic', !leaks($pg), implode(', ', leaks($pg)));
// It used to print "N mendesak" on the corner chip and again as the card
// subtitle — same card, same number, twice. Only the duplication is checked
// here; the value behind it belongs to verify_dispute_modal_ui.php.
check('the urgent count is still announced where it is true (topbar pill)',
    str_contains($pg, 'aduan mendesak') || str_contains($pg, 'Sistem beroperasi normal'),
    'neither the pill nor its calm fallback rendered');
check('the stat card shows at most one urgent chip, carrying one number',
    substr_count($pg, 'class="stat-urgent"') <= 1, substr_count($pg, 'class="stat-urgent"') . ' chips');
check('the admin urgent count is not repeated as the card subtitle',
    !preg_match('/class="stat-sub"[^>]*>\s*\d+\s*mendesak/is', $pg),
    'the same card used to print "N mendesak" twice');
check('the admin card subtitle no longer repeats the urgent count',
    str_contains($pg, 'Menunggu keputusan PTU'));

echo "\nPASS: $pass  FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
