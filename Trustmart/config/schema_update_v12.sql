-- ============================================================
-- schema_update_v12.sql
-- Return Shipment Tracking (Issue H3 - Dispute Refund Enforcement)
--
-- Problem: Buyer-favourable disputes trigger immediate refunds without
-- enforcing that buyer returns the goods. This adds a return_shipments
-- table to track return status and hold refunds until return is verified.
--
-- Fully idempotent: safe to run multiple times.
-- ============================================================

-- Return shipment tracking table
CREATE TABLE IF NOT EXISTS return_shipments (
    return_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispute_id INT UNSIGNED NOT NULL,
    transaction_id INT UNSIGNED NOT NULL,
    tracking_number VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'Shipping tracking number provided by buyer',
    carrier VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'Courier/carrier name (e.g., PosLaju, J&T)',
    status ENUM('pending','in_transit','received','verified','cancelled') NOT NULL DEFAULT 'pending'
        COMMENT 'Return shipment lifecycle: pending -> in_transit -> received -> verified',
    return_requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When admin requested return',
    shipped_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When buyer shipped the return',
    received_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When seller received the return',
    verified_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When admin verified return condition',
    notes TEXT NULL DEFAULT NULL
        COMMENT 'Admin notes about return condition',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (dispute_id) REFERENCES disputes(dispute_id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE,
    INDEX idx_dispute (dispute_id),
    INDEX idx_transaction (transaction_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks return shipments for buyer-favourable dispute resolutions';

-- Add new refund status for pending returns
ALTER TABLE payments 
    MODIFY COLUMN refund_status ENUM(
        'none',
        'requested',
        'pending_return',
        'processing',
        'completed',
        'failed'
    ) NOT NULL DEFAULT 'none'
    COMMENT 'Refund lifecycle with return hold state';

-- Return lifecycle states:
--   pending        -> Return requested, waiting for buyer to ship
--   in_transit     -> Buyer provided tracking, package in transit
--   received       -> Seller confirmed receipt of goods
--   verified       -> Admin verified goods condition, refund can proceed
--   cancelled      -> Return requirement waived/cancelled

-- Refund status with return integration:
--   none           -> No refund requested
--   requested      -> Refund queued but no return required
--   pending_return -> Refund on hold, waiting for return verification
--   processing     -> Refund submitted to gateway
--   completed      -> Refund confirmed done
--   failed         -> Refund attempt failed

-- ============================================================
-- Helper procedure: Check if refund can proceed
-- ============================================================
DROP PROCEDURE IF EXISTS can_proceed_refund;
DELIMITER //
CREATE PROCEDURE can_proceed_refund(
    IN p_payment_id INT UNSIGNED,
    OUT p_can_proceed TINYINT,
    OUT p_reason VARCHAR(255)
)
BEGIN
    DECLARE v_refund_status VARCHAR(20);
    DECLARE v_return_required TINYINT DEFAULT 0;
    DECLARE v_return_verified TINYINT DEFAULT 0;
    DECLARE v_dispute_id INT UNSIGNED DEFAULT NULL;
    
    -- Get payment refund status
    SELECT refund_status INTO v_refund_status
    FROM payments
    WHERE payment_id = p_payment_id;
    
    -- Check if return is required
    SELECT d.dispute_id, d.settlement_return_required
    INTO v_dispute_id, v_return_required
    FROM payments p
    JOIN transactions t ON t.transaction_id = p.transaction_id
    LEFT JOIN disputes d ON d.transaction_id = t.transaction_id
        AND d.settlement_status IN ('queued','completed')
    WHERE p.payment_id = p_payment_id
    LIMIT 1;
    
    -- If no return required, can proceed
    IF v_return_required = 0 OR v_dispute_id IS NULL THEN
        SET p_can_proceed = 1;
        SET p_reason = 'No return required';
    ELSE
        -- Check if return is verified
        SELECT COUNT(*) INTO v_return_verified
        FROM return_shipments
        WHERE dispute_id = v_dispute_id
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
-- Notes for implementation:
-- ============================================================
-- 1. Admin dispute resolution should create return_shipments record
--    when settlement_return_required = 1
-- 2. Payment refund should check return_shipments.status before proceeding
-- 3. Add buyer interface to submit tracking number
-- 4. Add seller interface to confirm receipt
-- 5. Add admin interface to verify condition and release refund

