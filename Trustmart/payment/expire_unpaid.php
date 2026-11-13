<?php
/**
 * payment/expire_unpaid.php
 *
 * Auto-expiry for abandoned online AND stale cash orders (fixes L2/L4 leaks).
 *
 * Online orders decrement product stock at creation (product.php) and, before
 * this job existed, an order that was never paid simply held that stock
 * forever because PAYMENT_TIMEOUT_MINUTES was defined but never enforced.
 *
 * This cancels online orders that are still unpaid / pending / failed and
 * older than PAYMENT_TIMEOUT_MINUTES, restoring stock and reducing sold_count.
 * A declined payment (payment_status = 'failed') must also be reclaimed,
 * otherwise an abandoned failed order reserves inventory forever.
 *
 * L4: cash orders never involve a gateway, so they are reclaimed on a separate
 * (much longer) window -- CASH_EXPIRY_DAYS, default 7 -- matching the reality
 * that hand-to-hand cash meetups are arranged offline.
 *
 * Designed to run repeatedly and safely:
 *   - one transaction + FOR UPDATE lock per order (no double-restore races)
 *   - never touches paid / already-cancelled orders
 *   - marks any still-pending payment row as failed
 *
 * Trigger from cron (XAMPP example):
 *   * * * * * "C:\xampp\php\php.exe" "C:\xampp\htdocs\Trustmart\payment\expire_unpaid.php"
 * Or hit it over HTTP from an external scheduler:
 *   GET http://localhost/Trustmart/payment/expire_unpaid.php?key=SECRET
 * HTTP runs REQUIRE TRUSTMART_CRON_KEY (env/.env) to be configured and the ?key
 * to match it; with no key set, HTTP is refused rather than allowed.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/../includes/payment_expiry.php';

header('Content-Type: application/json');

$isCli = (PHP_SAPI === 'cli');

// ── Shared-secret guard for HTTP invocation (fail closed) ────
// This used to be `if (!$isCli && $cronKey)`, which meant the endpoint was
// completely unauthenticated in the one state that is actually dangerous: a
// deployment that forgot to set the key. An anonymous GET here cancels pending
// payments and restores stock, so no key = no HTTP access.
$cronKey = getenv('TRUSTMART_CRON_KEY');
if (!$isCli) {
    if ($cronKey === false || $cronKey === '') {
        http_response_code(403);
        error_log('[expire_unpaid] Blocked HTTP trigger: TRUSTMART_CRON_KEY is not configured');
        echo json_encode([
            'success' => false,
            'message' => 'Forbidden — set TRUSTMART_CRON_KEY to allow HTTP triggering, or run this script from cron/CLI.'
        ]);
        exit;
    }
    $given = $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? '');
    if (!hash_equals((string)$cronKey, (string)$given)) {
        http_response_code(403);
        error_log('[expire_unpaid] Blocked HTTP trigger: key mismatch');
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
}

$timeout = max(1, (int) PAYMENT_TIMEOUT_MINUTES);

// Candidate orders: online, not yet paid (incl. a declined/failed attempt),
// still pending seller, created too long ago.
$stmt = $pdo->prepare(
    "SELECT transaction_id
       FROM transactions
      WHERE payment_type = 'online'
        AND payment_status IN ('unpaid', 'pending', 'failed')
        AND status = 'Menunggu'
        AND created_at < (NOW() - INTERVAL ? MINUTE)
      ORDER BY transaction_id ASC
      LIMIT 500"
);
$stmt->execute([$timeout]);
$candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);

$expired = [];
$cash_expired = [];
$skipped = 0;

foreach ($candidates as $txnId) {
    // The transition itself lives in includes/payment_expiry.php, shared with
    // the lazy check in payment/pay.php, so the two can never disagree about
    // what "expired" does to stock, order state and the gateway bill.
    $res = expire_stale_order($pdo, (int) $txnId, 'expire_unpaid');
    if ($res['ok']) {
        $expired[] = (int) $txnId;
    } else {
        // 'not-eligible' means the state moved under us (paid in the meantime)
        // or the row vanished — both are normal in a concurrent sweep.
        $skipped++;
    }
}

// ── L4: Cash orders never touch a gateway, so the online expiry above left
// abandoned cash orders holding stock forever. A cash order still sitting in
// 'Menunggu' with unpaid cash, past a generous hand-to-hand window, is dead:
// cancel it and release the inventory. The window is far longer than the
// online timeout because face-to-face cash meetups are arranged offline.
$cashDays = max(1, (int) (getenv('CASH_EXPIRY_DAYS') ?: 7));

$stmt = $pdo->prepare(
    "SELECT transaction_id
       FROM transactions
      WHERE payment_type = 'cash'
        AND payment_status = 'unpaid_cash'
        AND status = 'Menunggu'
        AND created_at < (NOW() - INTERVAL ? DAY)
      ORDER BY transaction_id ASC
      LIMIT 500"
);
$stmt->execute([$cashDays]);
$cashCandidates = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($cashCandidates as $txnId) {
    try {
        $pdo->beginTransaction();

        // Re-lock and re-verify: the seller may have confirmed cash or the
        // buyer may have cancelled between candidate selection and execution.
        $lock = $pdo->prepare(
            "SELECT transaction_id, product_id, quantity, status, payment_status
               FROM transactions
              WHERE transaction_id = ?
              FOR UPDATE"
        );
        $lock->execute([$txnId]);
        $txn = $lock->fetch(PDO::FETCH_ASSOC);

        if (!$txn
            || $txn['status'] !== 'Menunggu'
            || $txn['payment_status'] !== 'unpaid_cash') {
            $pdo->rollBack();
            $skipped++;
            continue;
        }

        // Restore stock / sold_count.
        $pdo->prepare(
            "UPDATE products
                SET stock = stock + ?,
                    sold_count = GREATEST(sold_count - ?, 0)
              WHERE product_id = ?"
        )->execute([$txn['quantity'], $txn['quantity'], $txn['product_id']]);

        // Cancel the abandoned cash order. payment_status stays 'unpaid_cash'
        // so the record reads "cancelled before cash was ever received".
        $pdo->prepare(
            "UPDATE transactions
                SET status = 'Dibatalkan',
                    cancelled_at = CURRENT_TIMESTAMP,
                    cancelled_by = 'system'
              WHERE transaction_id = ?"
        )->execute([$txnId]);

        $pdo->commit();
        $cash_expired[] = (int) $txnId;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[expire_unpaid/cash] txn ' . $txnId . ': ' . $e->getMessage());
        $skipped++;
    }
}

$result = [
    'success'   => true,
    'timeout_minutes' => $timeout,
    'cash_expiry_days' => $cashDays,
    'scanned'   => count($candidates) + count($cashCandidates),
    'expired'   => $expired,
    'expired_count' => count($expired),
    'cash_expired'   => $cash_expired,
    'cash_expired_count' => count($cash_expired),
    'skipped'   => $skipped,
    'at'        => date('c'),
];

error_log('[expire_unpaid] online=' . count($expired) . ' cash=' . count($cash_expired) . ' skipped=' . $skipped);

echo json_encode($result, JSON_PRETTY_PRINT);
