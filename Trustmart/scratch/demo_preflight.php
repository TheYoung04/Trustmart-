<?php
/*
 * demo_preflight.php — read-only readiness check before a live demo/consultation.
 *
 * Usage: C:\xampp\php\php.exe scratch/demo_preflight.php
 *
 * Nothing here writes to the database. It only reports whether each demo scene
 * has the data it needs to actually fire, so an empty admin panel or a checkout
 * that no longer gets blocked is caught before you are sitting in front of the
 * supervisor, not during. Exit code is non-zero when any scene is NO-GO.
 *
 * Scenes map to the 8-step run in TrustMart_Konsultasi_SV.pptx (slide 4).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_trust.php';
require_once __DIR__ . '/../includes/ai_trust.php';
require_once __DIR__ . '/../includes/payment_expiry.php';
// Local Apache root this build is served from (same constant the E2E guards use).
define('BASE', 'http://localhost/Trustmart');

$fail = 0;
function ok(string $what, string $detail = '') { echo "  [OK]    $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
function info(string $what, string $detail = '') { echo "  [INFO]  $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
// A NO-GO means a demo scene the deck promises will visibly fail.
function noGo(string $what, string $fix) {
    global $fail;
    $fail++;
    echo "  [NO-GO] $what\n          → $fix\n";
}

/* ── 1. Server reachable ───────────────────────────────────────── */
echo "\n1. PAUTAN\n";
// Probe the public storefront, not auth/login.php — that endpoint is POST-only
// by design and answers 405 to a GET, which is not a connectivity failure.
$ch = curl_init(BASE . '/index.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                       CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
$html = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code === 200 && is_string($html) && strlen($html) > 500) {
    ok('Apache + halaman awam', BASE . '/index.php');
} else {
    noGo('Laman tidak dapat dicapai (HTTP ' . $code . ')',
        'Start Apache in the XAMPP Control Panel, then re-run. Nothing else can be checked until this passes.');
}

/* ── 2. Catalogue (step 1-2: browse, product detail) ───────────── */
echo "\n2. KATALOG (demo langkah 1-2)\n";
$prod = $pdo->query("SELECT COUNT(*) c FROM products p JOIN users u ON p.seller_id=u.user_id
  WHERE p.status='Aktif' AND p.stock>0 AND u.is_verified=1 AND u.is_active=1")->fetch(PDO::FETCH_ASSOC);
$cats = $pdo->query("SELECT COUNT(DISTINCT p.category) c FROM products p JOIN users u ON p.seller_id=u.user_id
  WHERE p.status='Aktif' AND p.stock>0 AND u.is_verified=1 AND u.is_active=1")->fetchColumn();
$noimg = $pdo->query("SELECT COUNT(*) FROM products WHERE status='Aktif' AND (image_path IS NULL OR image_path='')")->fetchColumn();
if ((int) $prod['c'] >= 12) {
    ok('Produk tersedia untuk grid', $prod['c'] . ' produk, ' . $cats . ' kategori, ' . $noimg . ' tanpa gambar');
} else {
    noGo('Katalog terlalu tipis (' . $prod['c'] . ' produk aktif)',
        'Run scratch/seed_products_demo.php, then reset stock/is_active as it prints.');
}
if ((int) $noimg > 0) {
    noGo((int) $noimg . ' produk Aktif tiada gambar', 'Grid akan pecah — run scratch/backfill_product_images.php');
}

/* ── 3. AI hold scene (step 8: admin releases a held seller) ───── */
echo "\n3. GERBANG AI + PENAHANAN (demo langkah 8)\n";
if (!AI_TRUST_ENABLED) {
    noGo('Lapisan AI dimatikan (AI_TRUST_ENABLED=false)', 'Enable it in .env if the deck is going to show the AI panel.');
} else {
    $held = $pdo->prepare("SELECT tp.user_id, tp.fraud_risk, u.full_name,
            (SELECT COUNT(*) FROM products p WHERE p.seller_id=tp.user_id AND p.status='Aktif' AND p.stock>0) listings
        FROM trust_profiles tp JOIN users u ON u.user_id=tp.user_id
        WHERE tp.profile_role='penjual' AND tp.fraud_risk >= ? AND tp.ai_hold=1");
    $held->execute([AI_TRUST_HOLD_RISK]);
    $rows = $held->fetchAll(PDO::FETCH_ASSOC);
    $usable = array_filter($rows, fn($r) => (int) $r['listings'] > 0);
    if ($usable) {
        $names = implode(', ', array_map(fn($r) => $r['full_name'] . ' (risk ' . number_format((float) $r['fraud_risk'], 0) . ', ' . $r['listings'] . ' listing)', $usable));
        ok('Sekurang-kurangnya satu penjual DITAHAN + ada listing', $names);
    } else {
        noGo('Tiada penjual dalam keadaan dihalang — adegan "dihalang → admin lepaskan → beli berjaya" akan gagal sunyi',
            'arm it: UPDATE trust_profiles SET ai_hold=1, ai_hold_released_by=NULL, ai_hold_released_at=NULL,
             fraud_risk=' . AI_TRUST_HOLD_RISK . ' WHERE profile_role=\'penjual\' AND user_id=<seller id>; '
            . 'guard: scratch/test_ai_hold_e2e.php');
    }
    // The panel must also show the released state when the demo gets that far.
    $released = $pdo->query("SELECT COUNT(*) FROM trust_profiles WHERE profile_role='penjual'
        AND ai_hold=0 AND ai_hold_released_by IS NOT NULL")->fetchColumn();
    info('Profil yang sudah dilepaskan admin (papan tanda tarikh/penglepas)', (string) (int) $released);

    /* ── Audit #2: the checkout half of the script must have something to buy. ──
     * A held listing used to fail only when "Buat Pesanan" was clicked (403), which
     * made it impossible to tell a deliberate hold from a broken demo. The hold is
     * now announced on the card and on the product page by the same predicate this
     * check uses — so ask the question app-side, through ai_trust_seller_hold(),
     * rather than re-typing the threshold in SQL where it can drift from the gate. */
    $cand_sellers = $pdo->query(
        "SELECT DISTINCT p.seller_id, u.full_name
           FROM products p
           JOIN users u ON u.user_id = p.seller_id
          WHERE p.status='Aktif' AND p.stock > 0
            AND u.role='penjual' AND u.is_active=1 AND u.is_verified=1
          ORDER BY p.seller_id")->fetchAll(PDO::FETCH_ASSOC);
    $free = [];
    foreach ($cand_sellers as $s) {
        if (!ai_trust_seller_hold($pdo, (int) $s['seller_id'], 'penjual')) {
            $free[] = $s['full_name'];
        }
    }
    if ($free) {
        ok('Penjual boleh beli (tiada penahanan) untuk adegan checkout', implode(', ', array_slice($free, 0, 4)));
    } else {
        noGo('SEMUA penjual aktif ditahan AI — tiada satu pun produk boleh dibeli, adegan checkout mati',
            'Lepaskan satu penjual: UPDATE trust_profiles SET ai_hold=0, ai_hold_released_by=<admin id>,
             ai_hold_released_at=NOW() WHERE profile_role=\'penjual\' AND user_id=<seller>;');
    }
}

/* ── 4. Dispute scene (admin resolve → trust score effect) ─────── */
echo "\n4. DISPUTE (demo panel pentadbir)\n";
$open = $pdo->query("SELECT COUNT(*) c FROM disputes WHERE status IN ('Terbuka','Dalam Semakan')")->fetchColumn();
if ((int) $open > 0) {
    ok('Dispute terbuka untuk modal Selesaikan', $open . ' rekod');
} else {
    noGo('Tiada dispute terbuka — modal Selesaikan paparan kosong',
        'File one as a buyer on a completed order (buyer dashboard → order → lapor), or seed one: see docs/uat/UAT_SUMMARY.md A4.');
}
$return = $pdo->query("SELECT COUNT(*) c FROM return_shipments rs
    WHERE rs.status IN ('pending','in_transit','received')
      AND EXISTS (SELECT 1 FROM disputes d WHERE d.dispute_id=rs.dispute_id AND d.status<>'Selesai')")->fetchColumn();
if ((int) $return > 0) {
    ok('Pulangan tertunggak untuk gerbang refund (409 → verify → siap)', $return . ' rekod');
} else {
    info('Tiada pulangan tertunggak untuk gerbang refund',
        'ok — the gate itself is proven by scratch/test_e2e_refund_gate.php (13/13), not by seeded data');
}

/* ── 5. Seller verification gate (PTU approves a pending seller) ─ */
echo "\n5. PENJUAL BELUM DISAHKAN (gerbang PTU)\n";
$pending = $pdo->query("SELECT COUNT(*) c FROM users WHERE role='penjual' AND is_active=1
    AND (verification_status='Menunggu' OR is_verified=0)")->fetchColumn();
if ((int) $pending > 0) {
    ok('Akaun penjual menunggu kelulusan', $pending . ' akaun');
} else {
    noGo('Semua penjual demo sudah Disahkan — gerbang kelulusan PTU tidak boleh ditunjukkan',
        'Register one fresh seller account and leave it in Menunggu (deck slide 8 already lists this gap).');
}

/* ── 6. Payment / expiry scene ─────────────────────────────────── */
echo "\n6. BAYARAN\n";
$unpaid = $pdo->query("SELECT COUNT(*) c FROM transactions WHERE status='Menunggu'")->fetchColumn();
info('Order belum dibayar (rujukan bayaran tertangguh / Luput)', (int) $unpaid . ' rekod');

/* Audit #6 turned "payable forever" into a bounded window, which gives the Bayar
 * scene an expiry of its own: an unpaid online order seeded before the demo starts
 * can be Dibatalkan by the time it is clicked. Ask for one still inside the window,
 * measured in SQL by the same helper the gate itself uses. */
$timeout = payment_timeout_minutes();
$payable = $pdo->prepare(
    "SELECT transaction_id, TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_min
       FROM transactions
      WHERE status='Menunggu' AND payment_type='online'
        AND payment_status IN ('unpaid','pending','failed')
      HAVING age_min <= ?
      ORDER BY age_min ASC LIMIT 5");
$payable->execute([$timeout]);
$payable_rows = $payable->fetchAll(PDO::FETCH_ASSOC);
if ($payable_rows) {
    $young = (int) $payable_rows[0]['age_min'];
    ok('Order online boleh bayar untuk adegan "Bayar" (dalam tempoh ' . $timeout . ' minit)',
        'txn #' . $payable_rows[0]['transaction_id'] . ' berumur ' . $young . ' minit, ' . count($payable_rows) . ' pilihan');
} else {
    noGo('Tiada order online boleh bayar dalam tempoh ' . $timeout . ' minit — butang "Bayar" tiada sasaran, atau terus tamat',
        'php scratch/seed_pending_orders.php   (seeds SEED-PEND-PAYABLE with created_at=NOW(); re-run just before the demo — the window is only ' . $timeout . ' minutes)');
}

$paid = $pdo->query("SELECT COUNT(*) c FROM transactions WHERE status IN ('Disahkan','Selesai')")->fetchColumn();
info('Order sudah dibayar', (int) $paid . ' rekod');
// The seller console has exactly two reasons to show a badge, and both need an
// order parked in a specific state. A restore or a cleanup can silently empty
// them, and then the "Sah Terima Tunai" half of the demo has nothing to click —
// so ask for the states themselves rather than trusting a row count above.
$sellerAct = $pdo->query("SELECT
        SUM(status='Menunggu' AND payment_type='cash'   AND payment_status='unpaid_cash') tunai,
        SUM(status='Menunggu' AND payment_type='online' AND payment_status='paid')        sahkan
      FROM transactions")->fetch(PDO::FETCH_ASSOC);
if ((int) $sellerAct['tunai'] > 0 || (int) $sellerAct['sahkan'] > 0) {
    ok('Tindakan penjual tertunggak (butang "Sah Terima Tunai" / "Sahkan")',
        'tunai=' . (int) $sellerAct['tunai'] . ' sahkan=' . (int) $sellerAct['sahkan']);
} else {
    noGo('Tiada order menunggu tindakan penjual — badge penjual jadi 0 dan butang "Sah Terima Tunai" / "Sahkan" tidak muncul',
        'php scratch/seed_pending_orders.php   (creates one cash + one online order; --clear removes them again)');
}

/* ── 7. Login accounts on hand ─────────────────────────────────── */
echo "\n7. AKAUN LOGIN\n";
$roles = $pdo->query("SELECT role, COUNT(*) c FROM users WHERE is_active=1 GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (['pembeli', 'penjual', 'pentadbir'] as $r) {
    info("Akaun $r aktif", (string) (int) ($roles[$r] ?? 0));
}

/* ── 8. Automated guards still green (fast, self-cleaning) ─────── */
echo "\n8. PENGAWAL REGRESI (jalan sendiri, clean up)\n";
foreach ([
    // Runs first: it is the only guard that asks APACHE rather than the app, so
    // a stopped web server is reported here instead of cascading into every
    // HTTP assertion below.
    'verify_webroot_guard.php'          => 'pag web: .env/SQL/log tertutup, halaman aplikasi masih hidup',
    'test_receipt_fix.php'              => 'resit + aset CSS',
    'test_receipt_unavailable.php'      => 'papan "Resit Belum Tersedia"',
    'test_catalogue_variety.php'        => 'katalog berbilang kategori + label grid',
    'test_trust_explained.php'          => 'bahasa skor kepercayaan',
    'test_ai_hold_copy.php'             => 'papan tanda AI hold/release',
    'test_ai_gate.php'                  => 'gerbang AI di checkout',
    'test_e2e_refund_gate.php'          => 'gerbang refund DEFECT-002',
    'test_cash_payment_regression.php'  => 'bawah tunai: butang Sah Terima Tunai + hujung ke hujung',
    'verify_dispute_modal_ui.php'       => 'modal selesaikan aduan: Pesanan # + had pulangan',
    'verify_staff_accounts.php'         => 'akaun staf: daftar + no. pegawai + paparan identiti',
    'verify_notifications.php'          => 'badge tindakan pembeli + penjual: kiraan vs pangkalan data',
] as $file => $label) {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) { noGo("Pengawal $file tidak wujud", 'restore it before relying on it'); continue; }
    exec('C:\\xampp\\php\\php.exe ' . escapeshellarg($path) . ' 2>&1', $outLines, $rc);
    // Summary line format differs per harness; take the score line if present,
    // otherwise the last line that carries any text. ("N passed / N failed" and
    // "PASS: N  FAIL: N" are both in use, so match either phrasing.)
    $summary = '';
    foreach ($outLines as $line) {
        if (preg_match('/\d+\s*passed.*\d+\s*failed/i', $line)
            || preg_match('/PASS:\s*\d+.*FAIL:\s*\d+/i', $line)) { $summary = trim($line); break; }
    }
    if ($summary === '') {
        foreach ($outLines as $line) { if (trim($line) !== '') $summary = trim($line); }
    }
    if ($rc === 0) { ok("pengawal: $label", $summary !== '' ? $summary : 'PASS'); }
    else { noGo("pengawal GAGAL: $label", $summary !== '' ? $summary : 'lihat output di atas'); }
    $outLines = [];
}

echo "\n";
echo $fail === 0
    ? "KEPUTUSAN: GO — semua adegan demo ada data sokongan.\n"
    : "KEPUTUSAN: TIDAK GO — $fail item perlu ditutup dulu (lihat arahan → di atas).\n";
exit($fail === 0 ? 0 : 1);
