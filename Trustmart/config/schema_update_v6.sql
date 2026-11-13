-- ============================================================
-- TrustMart Payment System Schema v6
--
-- Adds online payment support with:
--   * Payment gateway integration (FPX, e-Wallet simulation + ToyyibPay)
--   * Payment tracking and verification
--   * Transaction payment status and references
--   * Refund support for cancelled online orders
--
-- Safe to run multiple times (uses IF NOT EXISTS).
-- ============================================================

SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- Payment records table for online transactions
CREATE TABLE IF NOT EXISTS payments (
  payment_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id       INT UNSIGNED NOT NULL,
  payment_reference    VARCHAR(100) NOT NULL COMMENT 'External gateway reference/bill code',
  payment_method       ENUM('fpx','ewallet','toyyibpay') NOT NULL,
  amount               DECIMAL(10,2) NOT NULL,
  currency             VARCHAR(3) NOT NULL DEFAULT 'MYR',
  status               ENUM('pending','processing','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  payment_url          VARCHAR(500) NULL DEFAULT NULL COMMENT 'Gateway payment page URL',
  paid_at              TIMESTAMP NULL DEFAULT NULL,
  refunded_at          TIMESTAMP NULL DEFAULT NULL,
  gateway_response     TEXT NULL DEFAULT NULL COMMENT 'JSON response from gateway for verification',
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (payment_id),
  UNIQUE KEY uq_payment_reference (payment_reference),
  KEY idx_payment_transaction (transaction_id),
  KEY idx_payment_status (status, created_at),
  CONSTRAINT fk_payment_transaction
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add payment tracking columns to transactions table
ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS payment_type ENUM('cash','online') NOT NULL DEFAULT 'cash' AFTER payment_method,
  ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid','pending','paid','failed','refunded') NULL DEFAULT NULL AFTER payment_type,
  ADD COLUMN IF NOT EXISTS paid_at TIMESTAMP NULL DEFAULT NULL AFTER payment_status,
  ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(100) NULL DEFAULT NULL AFTER paid_at;

-- Create index for payment queries
SET @idx_payment_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'transactions'
     AND index_name = 'idx_txn_payment'
);
SET @idx_payment_sql = IF(@idx_payment_exists = 0,
  'ALTER TABLE transactions ADD INDEX idx_txn_payment (payment_type, payment_status, paid_at)',
  'SELECT 1');
PREPARE idx_payment_stmt FROM @idx_payment_sql;
EXECUTE idx_payment_stmt;
DEALLOCATE PREPARE idx_payment_stmt;

-- Backfill payment status for existing transactions
-- Cash orders are implicitly paid on confirmation; online orders need explicit payment
UPDATE transactions
   SET payment_type = 'cash',
       payment_status = CASE 
         WHEN status IN ('Disahkan', 'Selesai') THEN 'paid'
         WHEN status = 'Dibatalkan' THEN NULL
         ELSE 'unpaid'
       END,
       paid_at = CASE 
         WHEN status IN ('Disahkan', 'Selesai') THEN COALESCE(confirmed_at, updated_at)
         ELSE NULL
       END
 WHERE payment_type = 'cash' AND payment_status IS NULL;

SET SQL_MODE = @OLD_SQL_MODE;

-- Migration complete
SELECT 'Payment system schema v6 applied successfully' AS status;
