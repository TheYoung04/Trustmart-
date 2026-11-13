<?php
/**
 * scratch/seed_pending_orders.php — the two orders a SELLER is waiting on.
 *
 * Why this exists: after the catalogue and AI scenes are seeded there is still
 * nothing for the seller console to act on, because both of its pending-action
 * reasons need an order parked in a specific state:
 *
 *   perlu_tunai   status='Menunggu' + payment_type='cash'   + payment_status='unpaid_cash'
 *                 → the "Sah Terima Tunai" button (the buyer handed over cash,
 *                   the seller has not recorded it)
 *   perlu_sahkan  status='Menunggu' + payment_type='online' + payment_status='paid'
 *                 → the escrow "Sahkan" button (money is in, seller must confirm)
 *
 * A third order is seeded for the BUYER side of the script: an online order in
 * Menunggu + 'unpaid' is the only thing that makes a "Bayar" button appear, and
 * since audit #6 closed that window it self-expires after PAYMENT_TIMEOUT_MINUTES
 * (30), so it has to be created with created_at = NOW() and re-seeded immediately
 * before the demonstration — an order seeded the day before would open on
 * "tempoh pembayaran telah tamat" instead of the gateway.
 *
 * demo_preflight's notification guard fails without at least one of these, and
 * the seller half of the demo cannot be shown at all. This creates one of each.
 *
 * The rows go in through the same column contract product.php and
 * payment/callback.php use, so the v13 trigger creates the cash_payments row by
 * itself and the trust triggers recalculate both parties exactly as they would
 * for a real order. Nothing here writes a score.
 *
 * Rows are marked with payment_reference LIKE 'SEED-PEND-%' (the convention
 * test_e2e_logic_fixes.php already uses) so re-running is a no-op and --clear
 * removes exactly what this script added, nothing more.
 *
 * Run:
 *   C:\xampp\php\php.exe scratch/seed_pending_orders.php
 *   C:\xampp\php\php.exe scratch/seed_pending_orders.php --clear
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only.\n"); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_trust.php';
require_once __DIR__ . '/../includes/ai_trust.php';

$clear = in_array('--clear', $argv ?? [], true);

/* ── --clear: remove only our own rows ─────────────────────────────── */
if ($clear) {
    $ids = $pdo->query("SELECT transaction_id FROM transactions
                        WHERE payment_reference LIKE 'SEED-PEND-%'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) { echo "Tiada order seed di sini (tiada payment_reference LIKE 'SEED-PEND-%').\n"; exit(0); }
    $pdo->beginTransaction();
    try {
        foreach ($ids as $id) {
            // Give back what checkout-style seeding took, or the listing ends up
            // permanently short one unit of stock every time this pair is
            // seeded and cleared.
            $row = $pdo->prepare("SELECT product_id, quantity FROM transactions WHERE transaction_id=?");
            $row->execute([$id]);
            $t = $row->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("DELETE FROM payments WHERE transaction_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM cash_payments WHERE transaction_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$id]);
            if ($t) {
                $pdo->prepare("UPDATE products SET stock = stock + ?,
                                   sold_count = GREATEST(sold_count - ?, 0)
                              WHERE product_id = ?")
                    ->execute([(int) $t['quantity'], (int) $t['quantity'], (int) $t['product_id']]);
            }
            echo "  dibuang txn #$id (stok dikembalikan)\n";
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "GAGAL: " . $e->getMessage() . "\n";
        exit(1);
    }
    echo "Selesai: " . count($ids) . " order seed dibuang.\n";
    exit(0);
}

echo "== TrustMart seed_pending_orders ==\n";

/* ── pick real parties: an active pembeli and non-held sellers with stock ──
 * "held" is decided by ai_trust_seller_hold(), the SAME function product.php
 * calls at checkout, not by a re-read of ai_hold. That matters: ai_hold defaults
 * to 1 on every profile, so filtering on the column alone would reject every
 * seller in the catalogue while the gate itself would happily let them trade
 * (the gate needs fraud_risk >= threshold AND ai_hold = 1). Copying the
 * predicate is how the two drift apart; calling the function is not.        */
$buyer = $pdo->query("SELECT user_id, full_name FROM users
                       WHERE role='pembeli' AND is_active=1 ORDER BY user_id LIMIT 1")
              ->fetch(PDO::FETCH_ASSOC);
if (!$buyer) { echo "Tiada akaun pembeli aktif — seed akaun dulu.\n"; exit(1); }

$prod = $pdo->prepare(
    "SELECT p.product_id, p.name, p.price, p.stock, p.sold_count, p.seller_id, u.full_name seller_name
       FROM products p JOIN users u ON u.user_id = p.seller_id
      WHERE p.status='Aktif' AND p.stock > 0 AND p.seller_id <> ?
        AND u.role='penjual' AND u.is_active=1
        AND u.verification_status = 'Disahkan'
      ORDER BY p.price ASC LIMIT 12");
$prod->execute([$buyer['user_id']]);
$cands = [];
$seen = [];          // seller ids already used, kept as ints for a strict test
foreach ($prod->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $sid = (int) $c['seller_id'];
    if (in_array($sid, $seen, true)) continue;
    if (ai_trust_seller_hold($pdo, $sid, 'penjual')) continue;
    // two DIFFERENT sellers, so each console shows exactly one thing to do and
    // the on-screen counts stay readable
    $seen[] = $sid;
    $cands[] = $c;
    if (count($cands) === 2) break;
}
if (count($cands) < 2) {
    echo "Perlukan produk daripada dua penjual berbeza yang Aktif + berstok + tidak ditahan AI — katalog terlalu tipis.\n";
    exit(1);
}

/**
 * One order, in the state the seller console needs.
 *
 * The stock/sold_count update is copied from product.php's own checkout
 * statement (stock - qty, sold_count + qty, guarded by stock >= qty and
 * rowCount === 1) rather than invented, so a seeded order is indistinguishable
 * from a clicked one in both the numbers and the "Terjual" label.
 *
 * $age_minutes is baked into the SQL as an integer interval, never a PHP clock,
 * so the row ages by the database's own clock — the same clock the expiry gate
 * (includes/payment_expiry.php) measures against.
 */
function make_order(PDO $pdo, array $buyer, array $p, string $kind, string $ref, int $age_minutes = 120): void {
    $exists = $pdo->prepare("SELECT transaction_id FROM transactions WHERE payment_reference=? LIMIT 1");
    $exists->execute([$ref]);
    if ($id = $exists->fetchColumn()) {
        echo "  ADA    txn #$id ($kind) — $ref sudah wujud\n";
        return;
    }

    $online  = in_array($kind, ['online', 'payable'], true);
    $age_sql = $age_minutes > 0 ? "(NOW() - INTERVAL " . $age_minutes . " MINUTE)" : 'NOW()';
    $pay_status = $kind === 'payable' ? 'unpaid' : ($online ? 'paid' : 'unpaid_cash');
    $pdo->beginTransaction();
    try {
        // Take the stock first, exactly as checkout does — if it moved since the
        // candidate query, bail out rather than oversell.
        $stock = $pdo->prepare(
            "UPDATE products SET stock = stock - 1, sold_count = sold_count + 1
              WHERE product_id = ? AND status = 'Aktif' AND stock >= 1");
        $stock->execute([$p['product_id']]);
        if ($stock->rowCount() !== 1) {
            $pdo->rollBack();
            echo "  SKIP   $kind: stok #{$p['product_id']} sudah berubah / habis\n";
            return;
        }

        $ins = $pdo->prepare(
            "INSERT INTO transactions
                (buyer_id, seller_id, product_id, quantity, total_amount, payment_method,
                 payment_type, payment_status, status, payment_reference, created_at)
             VALUES (?,?,?,1,?,?,?,?,'Menunggu',?, $age_sql)");
        $ins->execute([
            $buyer['user_id'], $p['seller_id'], $p['product_id'], $p['price'],
            $online ? 'E-Wallet' : 'Tunai',
            $online ? 'online' : 'cash',
            $pay_status,
            $ref,
        ]);
        $txn = (int) $pdo->lastInsertId();

        if ($kind === 'online') {
            // A paid online order without a payments row would be the stranded
            // state my own audit reported as a defect (issue #7), so give it the
            // gateway record callback.php would have written.
            $gatewayRef = 'TM' . date('YmdHis') . substr(md5($ref . $txn), 0, 6);
            $pay = $pdo->prepare(
                "INSERT INTO payments
                    (transaction_id, payment_reference, payment_method, amount, currency,
                     status, paid_at, payment_url, gateway_response, created_at)
                 VALUES (?,?, 'fpx', ?, 'MYR', 'completed', (NOW() - INTERVAL 120 MINUTE), ?, ?,
                         (NOW() - INTERVAL 120 MINUTE))");
            $pay->execute([$txn, $gatewayRef, $p['price'],
                'http://localhost/Trustmart/payment/gateway_sim.php?ref=' . $gatewayRef,
                json_encode(['gateway' => 'simulation', 'provider' => 'maybank',
                             'result' => 'approved', 'seeded' => true])]);
        }
        // kind 'payable' deliberately has NO payments row: the bill is created by
        // pay.php when the buyer actually starts the checkout, which is the whole
        // point of the scene.

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "  GAGAL  $kind: " . $e->getMessage() . "\n";
        exit(1);
    }

    printf("  TAMBAH txn #%-4s %-8s %-14s RM%-8s pembeli=%s penjual=%s  produk=%s\n",
        $txn, $kind, $pay_status, number_format((float)$p['price'], 2),
        $buyer['full_name'], $p['seller_name'], substr($p['name'], 0, 28));
}

make_order($pdo, $buyer, $cands[0], 'cash',    'SEED-PEND-CASH');
make_order($pdo, $buyer, $cands[1], 'online',  'SEED-PEND-ONLINE');
// Fresh (age 0) so the 30-minute payment window is still open when the demo starts.
make_order($pdo, $buyer, $cands[0], 'payable', 'SEED-PEND-PAYABLE', 0);

/* ── report the state the seller console will now show ─────────────── */
echo "\n-- kiraan tindakan penjual sekarang --\n";
foreach ($pdo->query(
    "SELECT u.user_id, u.full_name,
            SUM(t.status='Menunggu' AND t.payment_type='cash'   AND t.payment_status='unpaid_cash') perlu_tunai,
            SUM(t.status='Menunggu' AND t.payment_type='online' AND t.payment_status='paid')        perlu_sahkan
       FROM transactions t JOIN users u ON u.user_id = t.seller_id
      GROUP BY u.user_id HAVING perlu_tunai > 0 OR perlu_sahkan > 0
      ORDER BY u.user_id") as $r) {
    printf("  #%-4s %-22s tunai=%s sahkan=%s\n", $r['user_id'], $r['full_name'],
        $r['perlu_tunai'], $r['perlu_sahkan']);
}
// The v13 trigger is what makes the cash row real: no cash_payments row means
// the insert bypassed the contract.
$cp = (int) $pdo->query("SELECT COUNT(*) FROM cash_payments cp
                          JOIN transactions t ON t.transaction_id = cp.transaction_id
                         WHERE t.payment_reference LIKE 'SEED-PEND-CASH%'")->fetchColumn();
echo "\n  cash_payments terhasil daripada trigger: $cp (expect 1)\n";
echo "  Pulihkan: php scratch/seed_pending_orders.php --clear\n";
echo $cp === 1 ? "OK\n" : "AMARAN: trigger tidak berjalan — semak schema_update_v13\n";
exit($cp === 1 ? 0 : 1);
