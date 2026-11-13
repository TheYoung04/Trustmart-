-- ============================================================
-- schema_update_v11.sql
-- Buyer-favourable dispute settlement (Issue #7).
--
-- Resolving a dispute in the buyer's favour previously updated only the
-- dispute row (and trust via triggers); no refund/settlement task was ever
-- created, so the money never moved. This adds an EXPLICIT, persisted
-- settlement decision to `disputes` that covers refund amount, return
-- requirement and order-fulfilment outcome, plus a queue state so the
-- decision is idempotent and traceable.
--
-- Fully idempotent: safe to run any number of times (MariaDB
-- ADD COLUMN IF NOT EXISTS).
-- ============================================================

ALTER TABLE disputes
  ADD COLUMN IF NOT EXISTS settlement_status        ENUM('none','queued','completed') NOT NULL DEFAULT 'none'
      COMMENT 'Financial settlement state for a buyer-favourable decision' AFTER resolution,
  ADD COLUMN IF NOT EXISTS settlement_refund_amount DECIMAL(10,2) NULL DEFAULT NULL
      COMMENT 'Refund amount decided at settlement' AFTER settlement_status,
  ADD COLUMN IF NOT EXISTS settlement_return_required TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Whether the buyer must return the goods (1 = yes)' AFTER settlement_refund_amount,
  ADD COLUMN IF NOT EXISTS settlement_fulfilment    ENUM('Kekal Selesai','Dibatalkan') NULL DEFAULT NULL
      COMMENT 'Order fulfilment decision after settlement' AFTER settlement_return_required,
  ADD COLUMN IF NOT EXISTS settlement_notes         VARCHAR(500) NULL DEFAULT NULL
      COMMENT 'Admin notes for the settlement decision' AFTER settlement_fulfilment,
  ADD COLUMN IF NOT EXISTS settled_by               INT UNSIGNED NULL DEFAULT NULL
      COMMENT 'Admin who recorded the settlement' AFTER settlement_notes,
  ADD COLUMN IF NOT EXISTS settled_at               TIMESTAMP NULL DEFAULT NULL
      COMMENT 'When the settlement decision was recorded' AFTER settled_by;

-- Settlement state machine (recorded on disputes, money movement enqueued on payments):
--   none     -> no settlement (non buyer-favourable, or not yet resolved)
--   queued   -> decision persisted AND a refund task enqueued on the linked
--               completed payment (payments.refund_status='requested'), which
--               surfaces it in the admin refund queue for actual payout.
--   completed-> refund confirmed done (set when the linked payment refund completes)
--
-- NOTE: A buyer-favourable settlement NEVER auto-restocks products. The goods
-- may already have been delivered; restocking only happens through the normal
-- cancellation path, not dispute settlement.
