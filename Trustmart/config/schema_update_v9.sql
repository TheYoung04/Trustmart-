-- ============================================================
-- TrustMart AI Trust Layer schema v9  (TM-AI-1.0)
--
-- Adds an AUDITABLE, EXPLAINABLE AI signal layer that sits BESIDE the
-- deterministic Bayesian engine (TM-TRUST-2.0). The baseline score in
-- trust_profiles.score is NEVER modified; AI results are stored separately
-- so every adjustment can be traced and reverted.
--
-- Pure MySQL — no external services required. Safe to run repeatedly.
-- Target: MySQL / MariaDB (XAMPP + shared hosting).
--
-- Capabilities backed by this schema:
--   1. Circular-trading / collusion fraud detection   -> trust_ai_signals
--   2. Review-credibility scoring (heuristic or LLM)  -> review_ai_credibility
--   3. Chat-based trust & scam-language signals       -> trust_ai_signals
--   4. Hybrid final score persisted per role          -> trust_profiles.ai_*
-- ============================================================

SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ── 1. AI columns on trust_profiles (baseline stays untouched) ──
ALTER TABLE trust_profiles
  ADD COLUMN IF NOT EXISTS fraud_risk       DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Composite fraud likelihood 0-100' AFTER formula_version,
  ADD COLUMN IF NOT EXISTS ai_adjustment    DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'AI penalty applied to baseline (<=0)' AFTER fraud_risk,
  ADD COLUMN IF NOT EXISTS ai_trust_score   DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'Baseline + ai_adjustment; NULL until first AI pass' AFTER ai_adjustment,
  ADD COLUMN IF NOT EXISTS review_credibility DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'Mean credibility of this seller''s reviews 0-100' AFTER ai_trust_score,
  ADD COLUMN IF NOT EXISTS ai_flags         VARCHAR(255) NULL DEFAULT NULL COMMENT 'Comma-separated active reason codes' AFTER review_credibility,
  ADD COLUMN IF NOT EXISTS ai_model_version VARCHAR(32) NULL DEFAULT NULL AFTER ai_flags,
  ADD COLUMN IF NOT EXISTS ai_calculated_at TIMESTAMP NULL DEFAULT NULL AFTER ai_model_version;

-- ── 2. Per-signal audit table ────────────────────────────────
CREATE TABLE IF NOT EXISTS trust_ai_signals (
  signal_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  profile_role  ENUM('pembeli','penjual') NOT NULL,
  signal_code   VARCHAR(32) NOT NULL COMMENT 'circular_trading | review_credibility | scam_language | response_gap ...',
  signal_value  DECIMAL(6,2) NOT NULL DEFAULT 0.00 COMMENT 'Raw metric value',
  penalty       DECIMAL(6,2) NOT NULL DEFAULT 0.00 COMMENT 'Penalty contributed (<=0)',
  reason        VARCHAR(255) NULL DEFAULT NULL COMMENT 'Human-readable explanation',
  model_version VARCHAR(32) NOT NULL DEFAULT 'TM-AI-1.0',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (signal_id),
  KEY idx_ai_signal_user (user_id, profile_role, signal_code),
  CONSTRAINT fk_ai_signal_profile
    FOREIGN KEY (user_id, profile_role)
    REFERENCES trust_profiles (user_id, profile_role)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 3. Per-review credibility table (feeds capability #2) ──────
CREATE TABLE IF NOT EXISTS review_ai_credibility (
  review_id     INT UNSIGNED NOT NULL,
  credibility   DECIMAL(5,2) NOT NULL DEFAULT 50.00 COMMENT '0-100; 100 = fully credible genuine review',
  method        ENUM('heuristic','llm') NOT NULL DEFAULT 'heuristic',
  reasons       VARCHAR(255) NULL DEFAULT NULL COMMENT 'Why it was scored this way',
  model_version VARCHAR(32) NOT NULL DEFAULT 'TM-AI-1.0',
  calculated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (review_id),
  CONSTRAINT fk_review_cred
    FOREIGN KEY (review_id) REFERENCES reviews (review_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET SQL_MODE = @OLD_SQL_MODE;

SELECT 'AI Trust Layer schema v9 applied successfully' AS status;
