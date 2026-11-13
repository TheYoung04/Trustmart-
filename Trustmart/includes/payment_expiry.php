<?php
/**
 * includes/payment_expiry.php
 *
 * Single owner of the "this online order's payment window has closed"
 * transition: cancel it, release the stock it was holding and fail any
 * gateway bill still attached to it.
 *
 * Why a shared function instead of a second copy of the SQL: the reclaim was
 * written inline in payment/expire_unpaid.php, and PAYMENT_TIMEOUT_MINUTES was
 * only enforced there — i.e. only if a cron job actually runs. On a XAMPP
 * demo box nothing runs, so an unpaid order stayed payable forever (audit #6:
 * orders 4.6 days old were still accepted by payment/pay.php, and a declined
 * attempt kept its stock reserved indefinitely). Anything that discovers an
 * expired order now routes the transition through here, so the lazy check in
 * pay.php and the batch sweep in expire_unpaid.php cannot drift apart.
 *
 * Expiry is always compared in SQL with NOW(), never against PHP's clock, so
 * MySQL and PHP timezone settings cannot disagree (same rule as the resume
 * branch in pay.php).
 */

require_once __DIR__ . '/../config/payment.php';

if (!function_exists('payment_timeout_minutes')) {
    /** The one reading of the configured window, floored at a minute. */
    function payment_timeout_minutes(): int
    {
        // The window belongs to config/payment.php; pull it in rather than repeating
        // the number, so a caller that forgot the include gets the real timeout
        // instead of a stale copy that drifted when the value changed.
        if (!defined('PAYMENT_TIMEOUT_MINUTES')) {
            require_once __DIR__ . '/../config/payment.php';
        }
        return max(1, (int) PAYMENT_TIMEOUT_MINUTES);
    }
}

if (!function_exists('payment_order_age_minutes')) {
    /**
     * Age of an order in whole minutes, measured by the database clock.
     * Returns null when the order does not exist.
     */
    function payment_order_age_minutes(PDO $pdo, int $txn_id): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT TIMESTAMPDIFF(MINUTE, created_at, NOW()) FROM transactions
              WHERE transaction_id = ?'
        );
        $stmt->execute([$txn_id]);
        $age = $stmt->fetchColumn();
        return $age === false ? null : (int) $age;
    }
}

if (!function_exists('expire_stale_order')) {
    /**
     * Close the payment window on one online order that is still waiting on it.
     *
     * Safe to call from inside a caller's transaction (it then joins it) and
     * safe to call twice — the state is re-verified under a row lock, so a
     * concurrent payment that completed in the meantime is never clobbered.
     *
     * @param string $via  audit tag for the log ('pay.php', 'expire_unpaid', …)
     * @return array {ok: bool, reason: string} reason is 'expired' on success,
     *               otherwise 'not-eligible' (state changed / not an online
     *               unpaid order) or 'error'.
     */
    function expire_stale_order(PDO $pdo, int $txn_id, string $via = 'expiry'): array
    {
        // Nested-transaction safety: PDO throws if beginTransaction() runs
        // while a caller's transaction is open, and a rollback would discard
        // the caller's pending work. Same contract as process_payment_refund().
        $ownTransaction = !$pdo->inTransaction();

        try {
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }

            $lock = $pdo->prepare(
                'SELECT transaction_id, product_id, quantity, status,
                        payment_type, payment_status
                   FROM transactions
                  WHERE transaction_id = ?
                    FOR UPDATE'
            );
            $lock->execute([$txn_id]);
            $txn = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$txn
                || $txn['status'] !== 'Menunggu'
                || $txn['payment_type'] !== 'online'
                || !in_array($txn['payment_status'], ['unpaid', 'pending', 'failed'], true)) {
                if ($ownTransaction) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'reason' => 'not-eligible'];
            }

            // The order decremented stock when it was created, so an abandoned
            // one must give it back or the listing stays short forever.
            $pdo->prepare(
                'UPDATE products
                    SET stock = stock + ?,
                        sold_count = GREATEST(sold_count - ?, 0)
                  WHERE product_id = ?'
            )->execute([$txn['quantity'], $txn['quantity'], $txn['product_id']]);

            $pdo->prepare(
                "UPDATE transactions
                    SET status = 'Dibatalkan',
                        payment_status = 'failed',
                        cancelled_at = CURRENT_TIMESTAMP,
                        cancelled_by = 'system'
                  WHERE transaction_id = ?"
            )->execute([$txn_id]);

            // A bill still sitting at the gateway must not be collectable later.
            $pdo->prepare(
                "UPDATE payments
                    SET status = 'failed'
                  WHERE transaction_id = ? AND status IN ('pending', 'processing')"
            )->execute([$txn_id]);

            if ($ownTransaction) {
                $pdo->commit();
            }
            error_log("[payment_expiry] expired txn={$txn_id} via={$via} "
                . "was={$txn['payment_status']} qty={$txn['quantity']} product={$txn['product_id']}");
            return ['ok' => true, 'reason' => 'expired'];

        } catch (PDOException $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[payment_expiry] txn=' . $txn_id . ' via=' . $via . ': ' . $e->getMessage());
            return ['ok' => false, 'reason' => 'error'];
        }
    }
}
