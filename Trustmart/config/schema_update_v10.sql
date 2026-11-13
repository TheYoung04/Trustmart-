-- ============================================================
-- schema_update_v10.sql — AI Trust Layer: admin review hold
-- Idempotent: safe to run multiple times.
--
-- Adds a per-profile hold switch used by the order gate:
--   ai_hold = 1 → purchases from this seller are blocked while
--                 fraud_risk >= AI_TRUST_HOLD_RISK (admin review needed)
--   ai_hold = 0 → PTU admin has reviewed and released the hold.
-- The AI worker never touches ai_hold, so an admin release is durable.
-- ============================================================

ALTER TABLE trust_profiles
  ADD COLUMN IF NOT EXISTS ai_hold TINYINT(1) NOT NULL DEFAULT 1 AFTER ai_flags,
  ADD COLUMN IF NOT EXISTS ai_hold_released_by INT NULL DEFAULT NULL AFTER ai_hold,
  ADD COLUMN IF NOT EXISTS ai_hold_released_at TIMESTAMP NULL DEFAULT NULL AFTER ai_hold_released_by;

-- Confirm the AI layer is present (no-op SELECT for the migration runner).
SELECT COUNT(*) AS ai_signal_rows FROM trust_ai_signals;
