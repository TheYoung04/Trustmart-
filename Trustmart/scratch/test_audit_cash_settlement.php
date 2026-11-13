<?php
/**
 * scratch/test_audit_cash_settlement.php — LIVE guard for audit #4, #5, #7, #10.
 *
 * What it proves, against a running Apache + MySQL and the real endpoints:
 *   #7/#10  the cash rail's data invariants hold (vocabulary, receipts, and — the
 *           part my own first attempt got wrong — exactly ONE foreign key and ONE
 *           unique index on cash_payments.transaction_id, not a duplicate pair)
 *   #4      a buyer-favourable ruling on a CASH order is no longer told "order
 *           belum dibayar", and it surfaces somewhere an admin can act on
 *   #5      the settlement is closeable (settlement_status leaves 'queued'), the
 *           gate still holds it back when a return is demanded, and a PARTIAL
 *           refund is executed and recorded as partial instead of silently
 *           refunding the whole payment.
 *
 * Run: C:\xampp\php\php.exe scratch/test_audit_cash_settlement.php
 * Self-cleans: every crafted row is removed and product stock restored.
 */
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, $d = '') {
    global $pass, $fail;
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d !== '' ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}
$jar = sys_get_temp_dir() . '/tm_cashset.txt'; @unlink($jar);
function http($url, $post = null, $hdr = []) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 25]);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$c, (string) $b];
}
function token_from($b) {
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $b, $m)) return $m[1];
    if (preg_match('/name="_csrf_token" value="([^"]+)"/', $b, $m)) return $m[1];
    return '';
}

echo "== TrustMart audit guard: cash settlement + refund truth (#4 #5 #7 #10) ==\n";
echo "Target: $base\n";
[, $probe] = http("$base/public/");
if ($probe === '') { echo "TIADA respons dari $base/public/ — nyalakan Apache dulu.\n"; exit(2); }

$adminEmail = $pdo->query("SELECT email FROM users WHERE role='pentadbir' AND is_active=1 LIMIT 1")->fetchColumn();
$buyerId    = (int) $pdo->query("SELECT user_id FROM users WHERE role='pembeli' AND is_active=1 ORDER BY user_id LIMIT 1")->fetchColumn();
$prod       = $pdo->query("SELECT product_id, seller_id, price FROM products WHERE status='Aktif' AND stock > 2 ORDER BY product_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$adminEmail || !$buyerId || !$prod) { echo "Katalog/akaun tidak cukup untuk uji ini.\n"; exit(2); }
$productId = (int) $prod['product_id']; $sellerId = (int) $prod['seller_id'];
$snap = $pdo->query("SELECT stock, sold_count FROM products WHERE product_id=$productId")->fetch(PDO::FETCH_ASSOC);
echo "admin=$adminEmail buyer=$buyerId product=$productId seller=$sellerId (stock={$snap['stock']})\n";

[, $page] = http("$base/public/"); $tok = token_from($page);
http("$base/auth/login.php", ['email' => $adminEmail, 'password' => 'password123', '_csrf_token' => $tok],
   ['X-CSRF-Token: ' . $tok, 'Accept: application/json']);
[, $dash] = http("$base/dashboard/admin.php"); $tok = token_from($dash) ?: $tok;
if (stripos($dash, 'Papan Pemuka') === false && stripos($dash, 'admin') === false) {
    echo "LOGIN ADMIN GAGAL — tiada apa yang boleh diuji.\n"; exit(2);
}

$madeTxn = []; $madeDisp = []; $madeRet = [];
/** One order in a chosen payment state, plus an OPEN dispute on it. */
function craft(PDO $pdo, array &$madeTxn, array &$madeDisp, int $buyer, int $seller, int $product,
               string $type, string $pay_status, string $amount, bool $with_payment, string $tag) {
    $ref = 'E2ECASH_' . $tag . '_' . strtoupper(bin2hex(random_bytes(3)));
    $pdo->prepare("INSERT INTO transactions
            (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, payment_type,
             payment_status, status, payment_reference, created_at)
          VALUES (?,?,?,1,?,?,?,?, 'Disahkan', ?, NOW())")
        ->execute([$buyer, $seller, $product, $amount,
                   $type === 'cash' ? 'Tunai' : 'E-Wallet', $type, $pay_status, $ref]);
    $txn = (int) $pdo->lastInsertId(); $madeTxn[] = $txn;
    if ($with_payment) {
        $pdo->prepare("INSERT INTO payments
                (transaction_id, payment_reference, payment_method, amount, currency, status, refund_status, paid_at)
              VALUES (?,?,'fpx',?,'MYR','completed','none',NOW())")->execute([$txn, $ref, $amount]);
    }
    $pdo->prepare("INSERT INTO disputes (transaction_id, filed_by, title, description, severity, status)
                   VALUES (?,?,?,'Ujian guard #4/#5','Sederhana','Terbuka')")->execute([$txn, $buyer, 'Barang tidak seperti gambar']);
    $disp = (int) $pdo->lastInsertId(); $madeDisp[] = $disp;
    return [$txn, $disp, $ref];
}
function disp_row(PDO $pdo, int $d): array {
    return $pdo->query("SELECT status, settlement_status, settlement_refund_amount, settlement_return_required
                          FROM disputes WHERE dispute_id=$d")->fetch(PDO::FETCH_ASSOC);
}
function pay_row(PDO $pdo, string $ref): array {
    $r = $pdo->prepare("SELECT p.* FROM payments p WHERE p.payment_reference = ? LIMIT 1");
    $r->execute([$ref]); return $r->fetch(PDO::FETCH_ASSOC) ?: [];
}
function txn_row(PDO $pdo, int $t): array {
    return $pdo->query("SELECT status, payment_status FROM transactions WHERE transaction_id=$t")->fetch(PDO::FETCH_ASSOC);
}

try {
    /* ── A. Data invariants after migration v15 (#7 #10) ─────────────────── */
    echo "\n== A: invariants data (v15) ==\n";
    $bad_voc = (int) $pdo->query("SELECT COUNT(*) FROM transactions
        WHERE payment_type='cash' AND payment_status IN ('paid','unpaid','pending')")->fetchColumn();
    check("#7 tiada order tunai bergaya lama (paid/unpaid/pending)", $bad_voc === 0, "baki=$bad_voc");

    $missing_cp = (int) $pdo->query("SELECT COUNT(*) FROM transactions t
        LEFT JOIN cash_payments cp ON cp.transaction_id=t.transaction_id
        WHERE t.payment_type='cash' AND cp.cash_payment_id IS NULL")->fetchColumn();
    check("#7b setiap order tunai ada baris audit cash_payments", $missing_cp === 0, "tiada=$missing_cp");

    $orphans = (int) $pdo->query("SELECT COUNT(*) FROM cash_payments cp
        LEFT JOIN transactions t ON t.transaction_id=cp.transaction_id
        WHERE t.transaction_id IS NULL")->fetchColumn();
    check("#10 tiada resit yatim", $orphans === 0, "yatim=$orphans");

    $fks = (int) $pdo->query("SELECT COUNT(DISTINCT CONSTRAINT_NAME) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cash_payments'
          AND COLUMN_NAME='transaction_id' AND REFERENCED_TABLE_NAME='transactions'")->fetchColumn();
    $uniq = (int) $pdo->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cash_payments'
          AND COLUMN_NAME='transaction_id' AND NON_UNIQUE=0")->fetchColumn();
    // Exactly one each: a second foreign key is not "extra safety", it is two
    // rules that can be dropped independently and confuse every later guard.
    check("#10 tepat SATU foreign key pada cash_payments.transaction_id", $fks === 1, "fk=$fks");
    check("#10 tepat SATU unique index pada cash_payments.transaction_id", $uniq === 1, "index=$uniq");

    $col = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='payments' AND COLUMN_NAME='refund_amount'")->fetchColumn();
    check("#5 payments.refund_amount wujud", (int) $col === 1);

    $proc = $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()
        AND ROUTINE_NAME='can_proceed_settlement'")->fetchColumn();
    check("#4 prosedur can_proceed_settlement wujud", (int) $proc === 1);

    /* ── B. #4: a cash order IS treated as paid ─────────────────────────── */
    echo "\n== B: settlement ke atas order TUNAI (#4) ==\n";
    [$txnB, $dispB] = craft($pdo, $madeTxn, $madeDisp, $buyerId, $sellerId, $productId,
                            'cash', 'paid_cash', '88.00', false, 'CASH');
    [$c, $b] = http("$base/dashboard/admin.php?action=resolve_dispute",
        ['dispute_id' => $dispB, 'resolution' => 'Memihak Pembeli', 'status' => 'Selesai',
         'settle_refund_amount' => '88.00', 'settle_return_required' => '0',
         'settle_fulfilment' => 'Kekal Selesai', 'settle_notes' => 'tunai sudah diterima',
         '_csrf_token' => $tok], ['X-CSRF-Token: ' . $tok]);
    $jB = json_decode($b, true);
    check("resolve diterima (200 success)", $c === 200 && ($jB['success'] ?? false), substr($b, 0, 100));
    check("#4 TIDAK lagi berkata 'Order belum dibayar'",
        stripos($jB['message'] ?? '', 'belum dibayar') === false, $jB['message'] ?? '');
    check("#4 diberitahu ia perlu penyelesaian manual",
        stripos($jB['message'] ?? '', 'manual') !== false, $jB['message'] ?? '');
    $dB = disp_row($pdo, $dispB);
    check("settlement disimpan sebagai 'queued'", $dB['settlement_status'] === 'queued', json_encode($dB));

    /* ── C. #4: the queue can see it, and only as a manual row ──────────── */
    echo "\n== C: senarai Pulangan nampak settlement tunai (#4) ==\n";
    [, $rq] = http("$base/dashboard/admin.php?action=get_refunds&status=&page=1&per_page=50");
    $jQ = json_decode($rq, true);
    check("get_refunds berjaya", is_array($jQ) && ($jQ['success'] ?? false), substr($rq, 0, 80));
    $manual = $jQ['manual_settlements'] ?? null;
    check("kunci manual_settlements dihantar", is_array($manual));
    $mine = null;
    foreach ((array) $manual as $m) { if ((int) $m['dispute_id'] === $dispB) { $mine = $m; break; } }
    check("settlement tunai (#$dispB) ADA dalam senarai manual", is_array($mine),
          $mine ? 'txn=' . $mine['transaction_id'] . ' RM' . $mine['settlement_refund_amount'] : 'tiada');
    $in_gateway = false;
    foreach ((array) ($jQ['refunds'] ?? []) as $r) { if ((int) $r['transaction_id'] === $txnB) { $in_gateway = true; } }
    check("order tunai TIDAK menyamar sebagai pulangan gateway", !$in_gateway);

    /* ── D. #5: the return gate holds a manual settlement too ───────────── */
    echo "\n== D: gerbang pulangan untuk settlement (#5) ==\n";
    $pdo->prepare("UPDATE disputes SET settlement_return_required=1 WHERE dispute_id=?")->execute([$dispB]);
    $madeRet[] = (int) $pdo->query("SELECT dispute_id FROM disputes WHERE dispute_id=$dispB")->fetchColumn();
    $pdo->prepare("INSERT INTO return_shipments (dispute_id, transaction_id, status, notes, return_requested_at)
                   VALUES (?,?, 'pending', 'guard: belum disahkan', NOW())")->execute([$dispB, $txnB]);
    [$c2, $b2] = http("$base/dashboard/admin.php?action=complete_manual_settlement",
        ['dispute_id' => $dispB, 'notes' => 'cuba tutup awal', '_csrf_token' => $tok], ['X-CSRF-Token: ' . $tok]);
    $j2 = json_decode($b2, true);
    check("ditolak 409 semasa pulangan belum disahkan", $c2 === 409 && !($j2['success'] ?? true), substr($b2, 0, 100));
    check("settlement kekal 'queued' selepas ditolak", disp_row($pdo, $dispB)['settlement_status'] === 'queued');
    check("order tunai belum diletakkan sebagai 'refunded'",
          txn_row($pdo, $txnB)['payment_status'] !== 'refunded', json_encode(txn_row($pdo, $txnB)));

    // Now verify the return, and the same request must succeed.
    $pdo->prepare("UPDATE return_shipments SET status='verified', verified_at=NOW(), received_at=NOW()
                   WHERE dispute_id=?")->execute([$dispB]);
    [$c3, $b3] = http("$base/dashboard/admin.php?action=complete_manual_settlement",
        ['dispute_id' => $dispB, 'notes' => 'pulangan tunai diserah', '_csrf_token' => $tok], ['X-CSRF-Token: ' . $tok]);
    $j3 = json_decode($b3, true);
    check("diterima (200) selepas pulangan disahkan", $c3 === 200 && ($j3['success'] ?? false), substr($b3, 0, 110));
    $d3 = disp_row($pdo, $dispB);
    check("#5 settlement_status ditutup ('completed')", $d3['settlement_status'] === 'completed', json_encode($d3));
    check("order tunai kini 'refunded' (duit sudah kembali)",
          txn_row($pdo, $txnB)['payment_status'] === 'refunded', json_encode(txn_row($pdo, $txnB)));

    /* ── E. Idempotency: closing twice cannot double-settle ─────────────── */
    echo "\n== E: tutup dua kali (#5) ==\n";
    [$c4, $b4] = http("$base/dashboard/admin.php?action=complete_manual_settlement",
        ['dispute_id' => $dispB, 'notes' => 'kali kedua', '_csrf_token' => $tok], ['X-CSRF-Token: ' . $tok]);
    check("penutupan kedua ditolak (bukan senyap)", $c4 >= 400, "code=$c4");

    /* ── F. #5: a PARTIAL refund is honoured, not run as a full one ─────── */
    echo "\n== F: pulangan SEPARUH pada order dalam talian (#5) ==\n";
    [$txnF, $dispF, $refF] = craft($pdo, $madeTxn, $madeDisp, $buyerId, $sellerId, $productId,
                                   'online', 'paid', '200.00', true, 'PARTIAL');
    [$cF] = http("$base/dashboard/admin.php?action=resolve_dispute",
        ['dispute_id' => $dispF, 'resolution' => 'Memihak Pembeli', 'status' => 'Selesai',
         'settle_refund_amount' => '75.00', 'settle_return_required' => '0',
         'settle_fulfilment' => 'Kekal Selesai', '_csrf_token' => $tok], ['X-CSRF-Token: ' . $tok]);
    $pF = pay_row($pdo, $refF);
    check("refund dimasukkan ke queue (requested)", $cF === 200 && ($pF['refund_status'] ?? '') === 'requested',
          json_encode($pF));
    [$cR, $bR] = http("$base/dashboard/admin.php?action=retry_refund",
        ['payment_id' => $pF['payment_id'], '_csrf_token' => $tok], ['X-CSRF-Token: ' . $tok]);
    $jR = json_decode($bR, true);
    $pF2 = pay_row($pdo, $refF);
    check("retry Refund diterima", $cR === 200 && ($jR['success'] ?? false), substr($bR, 0, 110));
    check("#5 jumlah yang dipulangkan = 75.00, bukan 200.00",
          isset($jR['refund_amount']) && abs((float) $jR['refund_amount'] - 75.00) < 0.001,
          'amount=' . json_encode($jR['refund_amount'] ?? null));
    check("#5 is_partial dilaporkan true", ($jR['is_partial'] ?? false) === true, json_encode($jR['is_partial'] ?? null));
    check("#5 payments.refund_amount disimpan sebagai 75.00",
          isset($pF2['refund_amount']) && abs((float) $pF2['refund_amount'] - 75.00) < 0.001,
          'kolom=' . json_encode($pF2['refund_amount'] ?? null));
    check("#5 payment TIDAK ditanda 'refunded' (masih baki 125.00)",
          ($pF2['status'] ?? '') === 'completed', 'status=' . ($pF2['status'] ?? ''));
    check("#5 transaction TIDAK ditanda 'refunded' untuk sebahagian",
          txn_row($pdo, $txnF)['payment_status'] !== 'refunded', json_encode(txn_row($pdo, $txnF)));
    // The settlement decision (return RM75) is fully EXECUTED once that RM75 is
    // back, so the lifecycle closes even though the payment itself is NOT
    // blanket-'refunded' (RM125 was legitimately kept). Those are separate truths.
    check("#5 settlement ditutup ('completed') apabila jumlah yang diputuskan (RM75) telah dipulangkan",
          disp_row($pdo, $dispF)['settlement_status'] === 'completed', json_encode(disp_row($pdo, $dispF)));

    /* ── G. A FULL refund closes the lifecycle ──────────────────────────── */
    echo "\n== G: pulangan penuh menutup kitaran (#5) ==\n";
    [$txnG, $dispG, $refG] = craft($pdo, $madeTxn, $madeDisp, $buyerId, $sellerId, $productId,
                                   'online', 'paid', '60.00', true, 'FULL');
    http("$base/dashboard/admin.php?action=resolve_dispute",
        ['dispute_id' => $dispG, 'resolution' => 'Memihak Pembeli', 'status' => 'Selesai',
         'settle_refund_amount' => '60.00', 'settle_return_required' => '0',
         'settle_fulfilment' => 'Kekal Selesai', '_csrf_token' => $tok], ['X-CSRF-Token: ' . $tok]);
    $pG = pay_row($pdo, $refG);
    [, $bG] = http("$base/dashboard/admin.php?action=retry_refund",
        ['payment_id' => $pG['payment_id'], '_csrf_token' => $tok], ['X-CSRF-Token: ' . $tok]);
    $pG2 = pay_row($pdo, $refG);
    check("pulangan penuh: payments.status='refunded'", ($pG2['status'] ?? '') === 'refunded', json_encode($pG2));
    check("pulangan penuh: refund_amount = 60.00", abs((float) ($pG2['refund_amount'] ?? 0) - 60.00) < 0.001);
    check("pulangan penuh: transaction 'refunded'",
          txn_row($pdo, $txnG)['payment_status'] === 'refunded', json_encode(txn_row($pdo, $txnG)));
    check("#5 settlement ditutup ('completed') oleh alur gateway",
          disp_row($pdo, $dispG)['settlement_status'] === 'completed', json_encode(disp_row($pdo, $dispG)));
} finally {
    /* ── clean up: every crafted row, and only those ─────────────────────── */
    if ($madeTxn) {
        $in = implode(',', $madeTxn);
        $pdo->exec("DELETE FROM return_shipments WHERE transaction_id IN ($in)");
        $pdo->exec("DELETE FROM disputes  WHERE transaction_id IN ($in)");
        $pdo->exec("DELETE FROM payments  WHERE transaction_id IN ($in)");
        $pdo->exec("DELETE FROM cash_payments WHERE transaction_id IN ($in)");
        $pdo->exec("DELETE FROM transactions  WHERE transaction_id IN ($in)");
        // Seed-crafted orders never touched product stock (no checkout ran), but
        // the count above is cheap and keeps the inventory honest if that changes.
        $pdo->prepare("UPDATE products SET stock=?, sold_count=? WHERE product_id=?")
            ->execute([$snap['stock'], $snap['sold_count'], $productId]);
        echo "\ncleanup: " . count($madeTxn) . " order + baris berkaitan dibuang; stok produk dipulihkan.\n";
    }
}

echo "\n==================================\n";
echo "RESULT: $pass passed, $fail failed\n";
echo "==================================\n";
exit($fail === 0 ? 0 : 1);
