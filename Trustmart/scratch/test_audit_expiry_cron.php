<?php
/**
 * scratch/test_audit_expiry_cron.php — LIVE guard for audit #6 and #8.
 *
 * #6  payment/pay.php must refuse an online order that is already past
 *     PAYMENT_TIMEOUT_MINUTES — and, unlike a bare "not found", actually CLOSE
 *     it (cancel + return stock + fail the lingering bill) so a dead order
 *     cannot sit payable forever or hold inventory. A fresh order must still
 *     reach the checkout form untouched.
 * #8  the two cron endpoints (payment/expire_unpaid.php, ai_trust_worker.php)
 *     are authenticated over HTTP: no key / wrong key => 403, the real key
 *     from .env => allowed. Section #8b then spins up a throwaway php -S with
 *     the key env blanked to prove the fail-closed branch (HTTP refused when the
 *     key is NOT configured) — reachable nowhere else, since the running Apache
 *     always has the key set. No .env is touched.
 *
 * Run: C:\xampp\php\php.exe scratch/test_audit_expiry_cron.php [http://localhost/Trustmart]
 * Self-cleans: crafted orders removed, product stock restored to snapshot.
 */
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/includes/payment_expiry.php';

$pass = 0; $fail = 0;
function check($n, $c, $d = '') {
    global $pass, $fail;
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d !== '' ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}
$jar = sys_get_temp_dir() . '/tm_expcron.txt'; @unlink($jar);
function http($url, $post = null, $hdr = []) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 25]);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $head = substr((string) $resp, 0, $hsize); $body = substr((string) $resp, $hsize);
    return [$code, (string) $body, $head];
}
function token_from($b) {
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $b, $m)) return $m[1];
    if (preg_match('/name="_csrf_token" value="([^"]+)"/', $b, $m)) return $m[1];
    return '';
}
function header_value($head, $name) {
    foreach (explode("\r\n", $head) as $line) {
        if (stripos($line, $name . ':') === 0) return trim(substr($line, strlen($name) + 1));
    }
    return '';
}

echo "== TrustMart audit guard: payment expiry + cron authentication (#6 #8) ==\n";
echo "Target: $base\n";
[, $probe] = http("$base/public/");
if ($probe === '') { echo "TIADA respons dari $base/public/ — nyalakan Apache dulu.\n"; exit(2); }

// ── targets ──────────────────────────────────────────────────────────────────
$buyer = $pdo->query("SELECT user_id, email FROM users WHERE role='pembeli' AND is_active=1 ORDER BY user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$prod  = $pdo->query("SELECT product_id, seller_id, price FROM products WHERE status='Aktif' AND stock > 3 ORDER BY product_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$buyer || !$prod) { echo "Katalog/akaun tidak cukup untuk uji ini.\n"; exit(2); }
$productId = (int) $prod['product_id']; $sellerId = (int) $prod['seller_id']; $buyerId = (int) $buyer['user_id'];
$stock_before = (int) $pdo->query("SELECT stock FROM products WHERE product_id=$productId")->fetchColumn();
$timeout = payment_timeout_minutes();
echo "buyer={$buyer['email']} product=$productId (stock=$stock_before) timeout={$timeout}m\n";

// ── log in as the buyer (pay.php enforces ownership) ──────────────────────────
[, $page] = http("$base/public/"); $tok = token_from($page);
[, $lp] = http("$base/auth/login.php", ['email' => $buyer['email'], 'password' => 'password123', '_csrf_token' => $tok],
    ['X-CSRF-Token: ' . $tok, 'Accept: application/json']);
[, $bd] = http("$base/dashboard/buyer.php");
$logged_in = (stripos($bd, 'Papan') !== false || stripos($bd, 'pesanan') !== false || stripos($bd, 'buyer') !== false);
if (!$logged_in) { echo "LOGIN PEMBELI GAGAL — uji #6 endpoint tidak boleh jalan.\n"; }

$madeTxn = [];
function craft_online_order(PDO $pdo, array &$madeTxn, int $buyer, int $seller, int $product, string $status, string $pay_status, int $age_min, int $stock_before) {
    $ref = 'E2EEXP_' . strtoupper(bin2hex(random_bytes(3)));
    $pdo->prepare("INSERT INTO transactions
            (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, payment_type,
             payment_status, status, payment_reference, created_at)
          VALUES (?,?,?,1,'15.00','E-Wallet','online',?,?,?,(NOW() - INTERVAL ? MINUTE))")
        ->execute([$buyer, $seller, $product, $pay_status, $status, $ref, $age_min]);
    $txn = (int) $pdo->lastInsertId(); $madeTxn[] = $txn;
    // An online order decrements stock at creation; mirror that reservation so a
    // correct expiry has a stock restore to give back.
    $pdo->prepare("UPDATE products SET stock=? WHERE product_id=?")->execute([$stock_before - 1, $product]);
    return [$txn, $ref];
}

try {
    /* ── #6a: an order older than the window is CLOSED, not served ───────── */
    echo "\n== #6a: order LAMA (di luar window) ==\n";
    [$txnOld] = craft_online_order($pdo, $madeTxn, $buyerId, $sellerId, $productId, 'Menunggu', 'unpaid', $timeout + 60, $stock_before);
    if ($logged_in) {
        [$c, $b, $head] = http("$base/payment/pay.php?txn=$txnOld");
        $loc = header_value($head, 'Location');
        check("pay.php tidak paparkan form (redirect, bukan 200)", $c === 302 && stripos($loc, 'buyer.php') !== false, "code=$c loc=" . ($loc ?: '-'));
        $row = $pdo->query("SELECT status, payment_status, cancelled_by FROM transactions WHERE transaction_id=$txnOld")->fetch(PDO::FETCH_ASSOC);
        check("#6 order dibatalkan ('Dibatalkan')", ($row['status'] ?? '') === 'Dibatalkan', json_encode($row));
        check("#6 payment_status jadi 'failed'", ($row['payment_status'] ?? '') === 'failed', $row['payment_status'] ?? '');
        check("#6 pembatalan atas nama sistem", ($row['cancelled_by'] ?? '') === 'system', $row['cancelled_by'] ?? '');
        $stock_now = (int) $pdo->query("SELECT stock FROM products WHERE product_id=$productId")->fetchColumn();
        check("#6 stok dikembalikan semasa tamat tempoh", $stock_now === $stock_before, "stock=$stock_now (expect $stock_before)");
    } else {
        // Fallback without a session: prove the shared transition itself.
        $res = expire_stale_order($pdo, $txnOld, 'guard-fallback');
        check("#6 expire_stale_order menutup order lama", ($res['ok'] ?? false) === true, json_encode($res));
        $row = $pdo->query("SELECT status FROM transactions WHERE transaction_id=$txnOld")->fetch(PDO::FETCH_ASSOC);
        check("#6 status 'Dibatalkan'", ($row['status'] ?? '') === 'Dibatalkan', json_encode($row));
    }

    /* ── #6c: the expiry notice is shown where the buyer lands, then cleared ─ */
    if ($logged_in) {
        echo "\n== #6c: flash tamat-tempoh dipaparkan & dibersihkan di dashboard ==\n";
        // #6a set $_SESSION['error_msg'] then redirected here. buyer.php must
        // DISPLAY it (the user should learn their order expired) AND CLEAR it, or
        // it leaks onto the next page — a real mis-display seen in the browser
        // pass. Runs BEFORE #6b so pay.php's own form render cannot consume it first.
        [, $dash1] = http("$base/dashboard/buyer.php");
        check("#B mesej 'Tempoh pembayaran ... tamat' DIPAPARKAN di dashboard", stripos($dash1, 'empoh pembayaran') !== false, 'tiada');
        [, $dash2] = http("$base/dashboard/buyer.php");
        check("#B mesej DIBERSIHKAN selepas paparan (tidak bocor ke halaman lain)", stripos($dash2, 'empoh pembayaran') === false, 'masih ada');
    }

    /* ── #6b: a fresh order inside the window still reaches checkout ─────── */
    echo "\n== #6b: order BAHARU (dalam window) ==\n";
    [$txnNew] = craft_online_order($pdo, $madeTxn, $buyerId, $sellerId, $productId, 'Menunggu', 'unpaid', 0, $stock_before);
    if ($logged_in) {
        [$c2, $b2] = http("$base/payment/pay.php?txn=$txnNew");
        check("pay.php paparkan pilihan bayaran untuk order baharu", $c2 === 200 && stripos($b2, 'Kaedah Pembayaran') !== false, "code=$c2");
        $rowN = $pdo->query("SELECT status FROM transactions WHERE transaction_id=$txnNew")->fetch(PDO::FETCH_ASSOC);
        check("#6 order baharu TIDAK dibatalkan", ($rowN['status'] ?? '') === 'Menunggu', json_encode($rowN));
        // UI regression (surfaced by the browser pass): header.php's asset base
        // resolved to an empty prefix on one-directory-deep pages, so /payment/*
        // requested /payment/public/assets/… and 404'd the CSS+JS. A pay.php now
        // must emit the '../' base — a relative 'public/assets' (no ../) means the
        // bug is back.
        check("#A aset pada pay.php memakai base '../public/assets' (bukan 404)",
            stripos($b2, 'href="../public/assets') !== false && stripos($b2, 'href="public/assets') === false,
            'href=' . (preg_match('/href="[^"]*trustmart-ui\.css"/', $b2, $mm) ? $mm[0] : 'tiada'));
        // #6b must NOT re-show the already-consumed expiry flash on an unrelated order.
        check("#B mesej tamat-tempoh TIDAK bocor ke halaman pay order lain", stripos($b2, 'empoh pembayaran') === false);
    } else {
        check("(dilangkau — login pembeli gagal)", false, 'perlukan sesi pembeli');
    }

    /* ── #8: cron endpoints are authenticated over HTTP ─────────────────── */
    echo "\n== #8: pengesahan HTTP pada skrip cron ==\n";
    $envKeys = [];
    foreach (@file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2); $envKeys[trim($k)] = trim($v);
    }
    $tmKey = $envKeys['TRUSTMART_CRON_KEY'] ?? '';
    $aiKey = $envKeys['AI_TRUST_CRON_KEY'] ?? '';
    check("TRUSTMART_CRON_KEY dikonfigurasi dalam .env", $tmKey !== '', '');
    check("AI_TRUST_CRON_KEY dikonfigurasi dalam .env", $aiKey !== '', '');

    $cases = [
        ['payment/expire_unpaid.php', 'TRUSTMART', $tmKey],
        ['ai_trust_worker.php', 'AI_TRUST', $aiKey],
    ];
    foreach ($cases as [$script, $tag, $key]) {
        [$cn] = http("$base/$script"); // no key
        check("#8 $script: TANPA key => 403", $cn === 403, "code=$cn");
        [$cw, $bw] = http("$base/$script?key=tong_kong_silap"); // wrong key
        check("#8 $script: key SALAH => 403", $cw === 403, "code=$cw");
        check("#8 $script: key salah => 'Forbidden' sahaja (branch mismatch, bukan fail-closed)",
            stripos($bw, 'Forbidden') !== false && stripos($bw, 'allow HTTP triggering') === false, substr($bw, 0, 60));
        if ($key !== '') {
            [$cr, $br] = http("$base/$script?key=" . rawurlencode($key)); // right key
            check("#8 $script: key BETUL => DIBENARKAN (bukan 403)", $cr !== 403, "code=$cr " . substr($br, 0, 60));
        }
    }

    /* ── #8b: fail-closed when the key is NOT configured ─────────────────
       The real regression fix. Under the running Apache the key IS configured,
       so the `if ($cronKey === false || $cronKey === '')` branch is unreachable
       there. We spin up a throwaway php -S whose router blanks the key env (as a
       misconfigured host would) — WITHOUT touching .env — then prove the scripts
       refuse HTTP even when a valid key is supplied. (Old code `if (!$isCli &&
       $cronKey)` would have skipped authentication entirely in exactly this
       state.) PHP_SAPI under php -S is 'cli-server', so the guard really runs. */
    echo "\n== #8b: fail-closed bila key TIDAP dikonfigurasi (php -S terasing) ==\n";
    $router = $root . '/scratch/_tmp_failclosed_router_' . getmypid() . '.php';
    $logFile = sys_get_temp_dir() . '/tm_fc_srv.log';
    $proc = null;
    try {
        file_put_contents($router, "<?php\nputenv('TRUSTMART_CRON_KEY=');putenv('AI_TRUST_CRON_KEY=');\n\$_ENV['TRUSTMART_CRON_KEY']='';\$_ENV['AI_TRUST_CRON_KEY']='';\nreturn false;\n");
        $port = 8731;
        $fcBase = "http://127.0.0.1:$port";
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, $router],
            [['file', 'NUL', 'r'], ['file', $logFile, 'w'], ['file', $logFile, 'a']],
            $pipes
        );
        $up = false;
        for ($i = 0; $i < 60; $i++) {
            $s = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
            if ($s) { fclose($s); $up = true; break; }
            usleep(200000);
        }
        check("php -S fail-closed mula (port $port)", $up && is_resource($proc), $up ? 'up' : 'TIMED OUT');
        if ($up && is_resource($proc)) {
            foreach (['payment/expire_unpaid.php', 'ai_trust_worker.php'] as $script) {
                [$fn] = http("$fcBase/$script");
                check("#8b $script: tanpa key, key unset => 403", $fn === 403, "code=$fn");
            }
            // A VALID key must still be refused, because the SERVER has none configured:
            // authentication cannot be bypassed by guessing that a key exists. The
            // fail-closed branch's body ("...to allow HTTP triggering...") is what
            // distinguishes it from a plain key-mismatch "Forbidden".
            if ($tmKey !== '') {
                [$fk, $bk] = http("$fcBase/payment/expire_unpaid.php?key=" . rawurlencode($tmKey));
                check("#8b key BETUL tetap DITOLANG bila server tiada key (403 fail-closed)",
                    $fk === 403 && stripos($bk, 'allow HTTP triggering') !== false, "code=$fk " . substr($bk, 0, 70));
            }
        }
    } finally {
        if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
        if ($router && is_file($router)) @unlink($router);
        @unlink($logFile);
    }
} finally {
    if ($madeTxn) {
        $in = implode(',', $madeTxn);
        $pdo->exec("DELETE FROM payments WHERE transaction_id IN ($in)");
        $pdo->exec("DELETE FROM transactions WHERE transaction_id IN ($in)");
        $pdo->prepare("UPDATE products SET stock=? WHERE product_id=?")->execute([$stock_before, $productId]);
        echo "\ncleanup: " . count($madeTxn) . " order dibuang; stok dipulihkan ke $stock_before.\n";
    }
}

echo "\n==================================\n";
echo "RESULT: $pass passed, $fail failed\n";
echo "==================================\n";
exit($fail === 0 ? 0 : 1);
