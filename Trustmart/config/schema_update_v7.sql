-- ============================================================
-- TrustMart Refund System Schema v7
--
-- Adds comprehensive refund tracking for cancelled online payments:
--   * Refund request tracking and status workflow
--   * Gateway refund transaction IDs
--   * Automatic vs manual refund flagging
--   * Admin refund management
--
-- Safe to run multiple times (uses IF NOT EXISTS).
-- ============================================================

SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- Add refund tracking columns to payments table
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS refund_status ENUM('none','requested','processing','completed','failed') NOT NULL DEFAULT 'none' AFTER status,
  ADD COLUMN IF NOT EXISTS refund_requested_at TIMESTAMP NULL DEFAULT NULL AFTER refunded_at,
  ADD COLUMN IF NOT EXISTS refund_completed_at TIMESTAMP NULL DEFAULT NULL AFTER refund_requested_at,
  ADD COLUMN IF NOT EXISTS refund_reference VARCHAR(100) NULL DEFAULT NULL COMMENT 'Gateway refund transaction ID' AFTER refund_completed_at,
  ADD COLUMN IF NOT EXISTS refund_method ENUM('auto','manual','admin') NOT NULL DEFAULT 'auto' COMMENT 'How refund was initiated' AFTER refund_reference,
  ADD COLUMN IF NOT EXISTS refund_error TEXT NULL DEFAULT NULL COMMENT 'Error message if refund failed' AFTER refund_method,
  ADD COLUMN IF NOT EXISTS refund_attempts INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of refund attempts' AFTER refund_error;

-- Create index for refund queries
SET @idx_refund_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'payments'
     AND index_name = 'idx_payment_refund'
);
SET @idx_refund_sql = IF(@idx_refund_exists = 0,
  'ALTER TABLE payments ADD INDEX idx_payment_refund (refund_status, refund_requested_at)',
  'SELECT 1');
PREPARE idx_refund_stmt FROM @idx_refund_sql;
EXECUTE idx_refund_stmt;
DEALLOCATE PREPARE idx_refund_stmt;

-- Backfill refund status for existing refunded payments
UPDATE payments
   SET refund_status = 'completed',
       refund_requested_at = refunded_at,
       refund_completed_at = refunded_at,
       refund_method = 'manual'
 WHERE status = 'refunded' AND refund_status = 'none';

-- Update status column to include new refund-related statuses
-- Note: Can't ALTER ENUM in-place safely, but document the workflow:
-- Status flow: pending -> processing -> completed OR failed
--              completed -> (if cancelled) -> status stays 'completed' but refund_status tracks refund
-- Refund flow: none -> requested -> processing -> completed OR failed

SET SQL_MODE = @OLD_SQL_MODE;

-- Migration complete
SELECT 'Refund system schema v7 applied successfully' AS status;
