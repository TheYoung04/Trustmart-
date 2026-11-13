<?php
/**
 * CLI-only check of the receipt.php "unavailable" pages.
 * Uses a temp pembeli + temp transactions and self-cleans.
 * Run: php scratch/test_receipt_unavailable.php   (needs Apache + MySQL)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only\n"); }
$base = 'http://localhost/Trustmart';
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail) { echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . "\n"; $c ? $pass++ : $fail++; }
function fetch($url, $post = null, $hdr = [], $jar = null) {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false];
    if ($jar) { $opts[CURLOPT_COOKIEJAR] = $jar; $opts[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $opts);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$c, (string)$b, $loc];
}

$jar = sys_get_temp_dir() . '/tm_rcpt_u.txt'; @unlink($jar);
$jarS = sys_get_temp_dir() . '/tm_rcpt_s.txt'; @unlink($jarS);
$tmpId = 0; $tmpSeller = 0; $txns = [];

function back_link(string $html): string {
    return preg_match('/class="btn" href="([^"]+)"/', $html, $m) ? $m[1] : '(tiada pautan)';
}

try {
    // ── 0. temp pembeli (bypasses email activation) ──────────────
    $email = 'rcptest' . random_int(100000, 999999) . '@student.uthm.edu.my';
    $hash = password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("INSERT INTO users (full_name, matric_no, email, password_hash, role, faculty,
                                     is_active, is_verified, verification_status, trust_score)
                   VALUES ('RCP Empuji', 'RCP0', ?, ?, 'pembeli', 'FKEE', 1, 0, 'Menunggu', 100.00)")
        ->execute([$email, $hash]);
    $tmpId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE users SET matric_no=? WHERE user_id=?")->execute(['RCP' . $tmpId, $tmpId]);

    // ── 1. two Menunggu transactions: mine and someone else's ────
    $prod  = $pdo->query("SELECT product_id, seller_id FROM products WHERE status='Aktif' AND seller_id<>$tmpId LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $other = $pdo->query("SELECT user_id FROM users WHERE role='pembeli' AND user_id<>$tmpId LIMIT 1")->fetchColumn();
    $craft = $pdo->prepare("INSERT INTO transactions
        (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, payment_type, payment_status, status)
        VALUES (?, ?, ?, 1, 10.00, 'Tunai', 'cash', 'menunggu', 'Menunggu')");
    $craft->execute([$tmpId, $prod['seller_id'], $prod['product_id']]);
    $myTxn = (int)$pdo->lastInsertId(); $txns[] = $myTxn;
    $craft->execute([$other, $prod['seller_id'], $prod['product_id']]);
    $theirTxn = (int)$pdo->lastInsertId(); $txns[] = $theirTxn;
    // A live (Menunggu) order now renders a provisional receipt, so the only
    // state that legitimately hits the "Belum Tersedia" hold is a cancelled one.
    $pdo->prepare("INSERT INTO transactions
        (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, payment_type, payment_status, status)
        VALUES (?, ?, ?, 1, 10.00, 'Tunai', 'cash', 'unpaid_cash', 'Dibatalkan')")
        ->execute([$tmpId, $prod['seller_id'], $prod['product_id']]);
    $cancelTxn = (int)$pdo->lastInsertId(); $txns[] = $cancelTxn;
    echo "temp pembeli #$tmpId, txn $myTxn (mine) / $theirTxn (other) / $cancelTxn (cancelled)\n";

    // ── 2. login ─────────────────────────────────────────────────
    [, $page] = fetch("$base/public/", null, [], $jar);
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m); $tok = $m[1] ?? '';
    [$lc] = fetch("$base/auth/login.php",
                  ['email' => $email, 'password' => 'password123', '_csrf_token' => $tok],
                  ['X-CSRF-Token: ' . $tok, 'Accept: application/json'], $jar);
    check("temp user logs in (http $lc)", $lc === 200 || $lc === 302, $pass, $fail);

    [, $dash, $dloc] = fetch("$base/dashboard/buyer.php", null, [], $jar);
    check("session is live (dashboard 200)", str_contains((string)$dash, 'Papan Pemuka') || $dloc === null, $pass, $fail);

    // ── 3. each unavailable branch renders the friendly card ─────
    $cases = [
        'Pautan Tidak Sah'         => "$base/receipt.php",
        'Transaksi Tidak Dijumpai' => "$base/receipt.php?txn=999999",
        'Resit Belum Tersedia'     => "$base/receipt.php?txn=$cancelTxn",
        'Akses Ditolak'            => "$base/receipt.php?txn=$theirTxn",
    ];
    foreach ($cases as $want => $url) {
        [$c, $html, $loc] = fetch($url, null, [], $jar);
        preg_match('/<h1>(.*?)<\/h1>/s', $html, $mm); $got = trim($mm[1] ?? '(tiada kad)');
        check("'$want' kad dipaparkan (dapat: $got, http $c" . ($loc ? ", loc: $loc" : '') . ")",
              $c === 200 && $got === $want
              && str_contains($html, 'Kembali ke Papan Pemuka')
              && str_contains($html, 'box-sizing:border-box'),
              $pass, $fail);
    }

    // A Menunggu order the buyer just placed is NO LONGER held — it renders the
    // full provisional receipt flagged Pending.
    [$pc, $phtml] = fetch("$base/receipt.php?txn=$myTxn", null, [], $jar);
    check("Menunggu (mine) renders provisional receipt, not the hold card",
          $pc === 200 && str_contains($phtml, 'Maklumat Resit')
          && stripos($phtml, 'Pending') !== false
          && !str_contains($phtml, 'Resit Belum Tersedia'),
          $pass, $fail);

    // ── 4. "Kembali ke Papan Pemuka" must respect the mode, not just role ──
    // A penjual also shops in Mod Pembeli. When he is the BUYER of the order,
    // the link has to return him to buyer.php, never seller.php.
    $sEmail = 'rcps' . random_int(100000, 999999) . '@student.uthm.edu.my';
    $pdo->prepare("INSERT INTO users (full_name, matric_no, email, password_hash, role, faculty,
                                     is_active, is_verified, verification_status, trust_score)
                   VALUES ('RCP Penjual', 'RCP9', ?, ?, 'penjual', 'FKEE', 1, 1, 'Disahkan', 100.00)")
        ->execute([$sEmail, $hash]);
    $tmpSeller = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE users SET matric_no=? WHERE user_id=?")->execute(['RCP' . $tmpSeller, $tmpSeller]);

    // Routing lives on the hold card (the full receipt has no dashboard link),
    // so these use Dibatalkan orders — the state that still shows the card.
    $cancelCraft = $pdo->prepare("INSERT INTO transactions
        (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, payment_type, payment_status, status)
        VALUES (?, ?, ?, 1, 10.00, 'Tunai', 'cash', 'unpaid_cash', 'Dibatalkan')");
    $cancelCraft->execute([$tmpSeller, $prod['seller_id'], $prod['product_id']]);   // he buys
    $asBuyer = (int)$pdo->lastInsertId(); $txns[] = $asBuyer;
    $cancelCraft->execute([$other, $tmpSeller, $prod['product_id']]);               // he sells
    $asSeller = (int)$pdo->lastInsertId(); $txns[] = $asSeller;

    [, $pageS] = fetch("$base/public/", null, [], $jarS);
    preg_match('/name="csrf-token" content="([^"]+)"/', $pageS, $mS); $tokS = $mS[1] ?? '';
    fetch("$base/auth/login.php",
          ['email' => $sEmail, 'password' => 'password123', '_csrf_token' => $tokS],
          ['X-CSRF-Token: ' . $tokS, 'Accept: application/json'], $jarS);

    [, $hBuy] = fetch("$base/receipt.php?txn=$asBuyer", null, [], $jarS);
    $linkBuy = back_link($hBuy);
    check("penjual as BUYER returns to buyer.php (dapat: $linkBuy)",
          $linkBuy === 'dashboard/buyer.php', $pass, $fail);

    [, $hSell] = fetch("$base/receipt.php?txn=$asSeller", null, [], $jarS);
    $linkSell = back_link($hSell);
    check("penjual as SELLER returns to seller.php (dapat: $linkSell)",
          $linkSell === 'dashboard/seller.php', $pass, $fail);

    [, $hMode] = fetch("$base/receipt.php?txn=999999&mode=pembeli", null, [], $jarS);
    $linkMode = back_link($hMode);
    check("?mode=pembeli hint honoured when no transaction context (dapat: $linkMode)",
          $linkMode === 'dashboard/buyer.php', $pass, $fail);
    echo "\n== $pass passed, $fail failed ==\n";
} finally {
    foreach ($txns as $t) $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$t]);
    // the INSERTs fired trust triggers — replay them so no residue remains
    if (!empty($prod['seller_id'])) $pdo->prepare("CALL recalculate_trust_score(?)")->execute([$prod['seller_id']]);
    if (!empty($other))              $pdo->prepare("CALL recalculate_buyer_trust_score(?)")->execute([$other]);
    foreach ([$tmpId, $tmpSeller] as $id) {
        if (!$id) continue;
        $pdo->prepare("DELETE FROM trust_profiles WHERE user_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$id]);
    }
    @unlink($jar); @unlink($jarS);
    echo "temp data removed\n";
}
