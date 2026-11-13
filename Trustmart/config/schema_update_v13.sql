-- ============================================================
-- schema_update_v13.sql
-- Cash Payment Tracking (Issue H4)
--
-- Problem: Cash orders have payment_status=NULL with no audit trail.
-- Sellers can confirm without verifying payment, buyers can complete
-- without paying. This adds proper cash payment states and tracking.
--
-- Fully idempotent: safe to run multiple times.
-- ============================================================

-- Update payment_status ENUM to include cash-specific states
ALTER TABLE transactions
    MODIFY COLUMN payment_status ENUM(
        'unpaid',
        'unpaid_cash',
        'pending',
        'paid',
        'paid_cash',
        'failed',
        'refunded'
    ) NULL DEFAULT NULL
    COMMENT 'Payment lifecycle including cash-specific states';

-- Payment status states:
--   NULL           -> Legacy orders (before this fix)
--   unpaid         -> Online payment not yet initiated
--   unpaid_cash    -> Cash order awaiting seller confirmation of payment
--   pending        -> Online payment initiated, waiting for gateway callback
--   paid           -> Online payment confirmed by gateway
--   paid_cash      -> Cash payment confirmed by seller
--   failed         -> Payment attempt failed
--   refunded       -> Payment refunded

-- Create cash_payments table for audit trail
CREATE TABLE IF NOT EXISTS cash_payments (
    cash_payment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNSIGNED NOT NULL,
    confirmed_by_seller TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Whether seller confirmed receiving cash',
    seller_confirmed_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When seller confirmed cash receipt',
    seller_notes TEXT NULL DEFAULT NULL
        COMMENT 'Seller notes about cash payment',
    amount_received DECIMAL(10,2) NULL DEFAULT NULL
        COMMENT 'Amount seller confirmed receiving',
    payment_method_detail VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'E.g., "Hand-to-hand", "COD", "Bank deposit"',
    evidence_photo_path VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Optional photo of receipt/proof',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE,
    UNIQUE KEY unique_transaction (transaction_id),
    INDEX idx_confirmed (confirmed_by_seller),
    INDEX idx_seller_confirmed_at (seller_confirmed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit trail for cash payment confirmations';

-- Backfill existing cash orders with unpaid_cash status
UPDATE transactions
SET payment_status = 'unpaid_cash'
WHERE payment_type = 'cash'
  AND payment_status IS NULL
  AND status = 'Menunggu';

-- Backfill confirmed cash orders with paid_cash status
UPDATE transactions
SET payment_status = 'paid_cash'
WHERE payment_type = 'cash'
  AND payment_status IS NULL
  AND status IN ('Disahkan', 'Selesai');

-- Create cash_payments records for existing confirmed cash orders (backfill)
INSERT INTO cash_payments (transaction_id, confirmed_by_seller, seller_confirmed_at, amount_received)
SELECT 
    transaction_id,
    1,
    confirmed_at,
    total_amount
FROM transactions
WHERE payment_type = 'cash'
  AND status IN ('Disahkan', 'Selesai')
  AND confirmed_at IS NOT NULL
  AND transaction_id NOT IN (SELECT transaction_id FROM cash_payments)
ON DUPLICATE KEY UPDATE 
    confirmed_by_seller = 1,
    seller_confirmed_at = VALUES(seller_confirmed_at);

-- ============================================================
-- Helper function: Check if cash payment is confirmed
-- ============================================================
DROP FUNCTION IF EXISTS is_cash_payment_confirmed;
DELIMITER //
CREATE FUNCTION is_cash_payment_confirmed(p_transaction_id INT UNSIGNED)
RETURNS TINYINT(1)
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_confirmed TINYINT(1) DEFAULT 0;
    
    SELECT confirmed_by_seller INTO v_confirmed
    FROM cash_payments
    WHERE transaction_id = p_transaction_id
    LIMIT 1;
    
    RETURN COALESCE(v_confirmed, 0);
END //
DELIMITER ;

-- ============================================================
-- Trigger: Create cash_payments record when cash order created
-- ============================================================
DROP TRIGGER IF EXISTS after_cash_transaction_insert;
DELIMITER //
CREATE TRIGGER after_cash_transaction_insert
AFTER INSERT ON transactions
FOR EACH ROW
BEGIN
    IF NEW.payment_type = 'cash' THEN
        INSERT INTO cash_payments (transaction_id, confirmed_by_seller, amount_received)
        VALUES (NEW.transaction_id, 0, NEW.total_amount)
        ON DUPLICATE KEY UPDATE transaction_id = transaction_id;
    END IF;
END //
DELIMITER ;

