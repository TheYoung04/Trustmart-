-- ============================================================
-- schema_update_v15.sql
-- Cash payment rail: vocabulary, audit trail and refund truth
--
-- Three related defects all trace back to the same gap: the cash rail was
-- bolted on after the schema existed, and the rows written before it (plus the
-- seeder/test traffic that grew around it) never conformed to it.
--
--   #7  schema_update_v13.sql defined payment_status='paid' as "Online payment
--       confirmed by gateway" and introduced 'paid_cash' / 'unpaid_cash' for
--       cash, but its backfill only touched rows whose payment_status was NULL.
--       Anything that already carried 'paid'/'unpaid' was skipped, so cash
--       orders still speak the legacy vocabulary. Consequences measured live:
--       a completed, seller-confirmed cash order rendering "Belum Bayar", and
--       admin.php's dispute settlement testing payment_status='paid' to decide
--       whether money was ever received — so a buyer who paid CASH was told
--       "Order belum dibayar" and no refund was queued (#4).
--
--   #7b v13's cash_payments backfill additionally required confirmed_at IS NOT
--       NULL. Cash orders completed before the confirmation column existed have
--       NULL there, so seven finished cash sales sit with no row in the very
--       table that is supposed to audit them — in a system whose pitch is
--       auditability.
--
--   #10 receipts existed that pointed at orders which no longer exist: an audit
--       row asserting the seller confirmed cash for a transaction that cannot be
--       verified. Root cause measured, not assumed: cash_payments ALREADY
--       carries FOREIGN KEY (transaction_id) REFERENCES transactions
--       ON DELETE CASCADE (cash_payments_ibfk_1) and UNIQUE (transaction_id)
--       (unique_transaction) from the base schema, so the application cannot
--       orphan a receipt. scratch/db_reset_demo.php does
--       `SET FOREIGN_KEY_CHECKS = 0`, and MariaDB does not re-validate existing
--       rows when checks are switched back on — so test-harness cleanup and
--       restore paths are what produce orphans. The constraint therefore stays
--       as it is; what v15 adds is the repair (step 4) plus guards that only
--       create a constraint when the column genuinely has none.
--
--   #5  payments has no column for the amount actually refunded, so a partial
--       refund decided in the settlement form (disputes.settlement_refund_amount)
--       can only be executed as a full one, and nothing records what left the
--       building.
--
-- Fully idempotent: safe to run repeatedly, and safe to run against a dump
-- that predates it (the backfills come before the structural changes).
-- ============================================================

-- 1. What was ACTUALLY refunded, which is not always payments.amount.
--    NULL means "no refund recorded", so pre-existing rows stay honest.
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS refund_amount DECIMAL(10,2) DEFAULT NULL
    COMMENT 'Amount actually returned; NULL until a refund is recorded (partial refunds are legitimate)'
    AFTER amount;

-- 2. Cash vocabulary backfill (audit #7).
--    'paid' -> 'paid_cash' and 'unpaid' -> 'unpaid_cash' for cash rows only.
--    Online rows are untouched: for them 'paid' genuinely means the gateway
--    confirmed the money, which is exactly the meaning cash cannot claim.
UPDATE transactions
   SET payment_status = 'paid_cash'
 WHERE payment_type = 'cash'
   AND payment_status = 'paid';

UPDATE transactions
   SET payment_status = 'unpaid_cash'
 WHERE payment_type = 'cash'
   AND payment_status = 'unpaid';

-- 'pending' is a gateway-bill state that no cash order can legitimately be in;
-- such a row means the order was created but never handed over, so it belongs
-- with the other cash-awaiting-confirmation rows.
UPDATE transactions
   SET payment_status = 'unpaid_cash'
 WHERE payment_type = 'cash'
   AND payment_status = 'pending';

-- 3. Restore the missing cash audit rows (#7b).
--    Deliberately written with confirmed_by_seller = 0 and seller_confirmed_at
--    = NULL: the seller's confirmation of receipt was never recorded for these
--    orders, and inventing a timestamp here would put a false statement in the
--    audit table — the exact failure mode #10 describes. The amount is still
--    recorded, because the order total is evidenced by the completed order
--    itself, and the note says where the row came from.
INSERT INTO cash_payments
        (transaction_id, confirmed_by_seller, seller_notes, amount_received)
SELECT t.transaction_id, 0,
       'Disi oleh migrasi v15: urus niaga tunai yang direkodkan sebelum jadual audit kewujudan — pengesahan penerimaan tunai oleh penjual TIDAK direkodkan pada asal.',
       t.total_amount
  FROM transactions t
 WHERE t.payment_type = 'cash'
   AND t.transaction_id NOT IN (SELECT transaction_id FROM cash_payments);

-- 4. Remove receipts that point at orders which no longer exist (#10).
--    These rows assert something about a transaction that cannot be verified,
--    so they are not an audit trail — they are noise that a reviewer would
--    rightly read as a fabricated confirmation.
DELETE FROM cash_payments
 WHERE transaction_id NOT IN (SELECT transaction_id FROM transactions);

-- 5. Make the guarantees structural, but only where they are genuinely absent.
--    Match on the COLUMN, not on a constraint name: MariaDB has no
--    "ADD CONSTRAINT IF NOT EXISTS", and keying the guard on this file's own
--    name would re-add a second foreign key next to the base schema's
--    cash_payments_ibfk_1. ON DELETE CASCADE is what keeps legitimate order
--    deletion working (test harnesses clean up their own rows) while making it
--    impossible to orphan a receipt.
SET @fk := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE cash_payments ADD CONSTRAINT fk_cash_payments_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (transaction_id) ON DELETE CASCADE',
    'DO 0')
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_payments'
   AND COLUMN_NAME = 'transaction_id'
   AND REFERENCED_TABLE_NAME = 'transactions');
PREPARE stmt FROM @fk; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. One receipt per order. The v13 trigger and seller.php both upsert on this
--    key, so without the index a second row for the same order would silently
--    become a second, contradictory confirmation. Guarded the same way: the
--    base schema already names it unique_transaction.
SET @idx := (SELECT IF(COUNT(*) = 0,
    'CREATE UNIQUE INDEX uq_cash_payments_transaction ON cash_payments (transaction_id)',
    'DO 0')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_payments'
   AND COLUMN_NAME = 'transaction_id'
   AND NON_UNIQUE = 0);
PREPARE stmt FROM @idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. The cash sibling of can_proceed_refund() (v12).
--    A cash settlement has no payments row, so the gateway-side gate cannot be
--    reached for it — which is how a buyer-favourable ruling on a cash order
--    ends up with nothing to enforce it and nobody to close it. The rule itself
--    is identical: if the settlement demands the goods back, the money may not
--    be signed off until that return is verified. It lives here, next to its
--    twin, rather than being re-typed in PHP, so the two cannot drift.
DROP PROCEDURE IF EXISTS can_proceed_settlement;
DELIMITER //
CREATE PROCEDURE can_proceed_settlement(
    IN p_dispute_id INT UNSIGNED,
    OUT p_can_proceed TINYINT,
    OUT p_reason VARCHAR(255)
)
BEGIN
    DECLARE v_return_required TINYINT DEFAULT 0;
    DECLARE v_return_verified TINYINT DEFAULT 0;

    SELECT settlement_return_required INTO v_return_required
      FROM disputes
     WHERE dispute_id = p_dispute_id;

    IF v_return_required IS NULL THEN
        SET p_can_proceed = 0;
        SET p_reason = 'Settlement not found';
    ELSEIF v_return_required = 0 THEN
        SET p_can_proceed = 1;
        SET p_reason = 'No return required';
    ELSE
        SELECT COUNT(*) INTO v_return_verified
          FROM return_shipments
         WHERE dispute_id = p_dispute_id
           AND status = 'verified';

        IF v_return_verified > 0 THEN
            SET p_can_proceed = 1;
            SET p_reason = 'Return verified';
        ELSE
            SET p_can_proceed = 0;
            SET p_reason = 'Waiting for return verification';
        END IF;
    END IF;
END //
DELIMITER ;

-- ============================================================
-- Sanity checks after applying:
--   SELECT payment_type, payment_status, COUNT(*) FROM transactions
--    GROUP BY payment_type, payment_status;                  -- no cash|paid|unpaid|pending
--   SELECT COUNT(*) FROM cash_payments cp LEFT JOIN transactions t
--     ON t.transaction_id = cp.transaction_id WHERE t.transaction_id IS NULL;  -- 0
--   SELECT COUNT(*) FROM transactions t LEFT JOIN cash_payments cp
--     ON cp.transaction_id = t.transaction_id
--    WHERE t.payment_type='cash' AND cp.cash_payment_id IS NULL;               -- 0
-- ============================================================
