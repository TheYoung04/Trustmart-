-- ============================================================
-- config/schema_update_v2.sql
-- TrustMart Schema Update v2 — Password Reset Support
-- Run this AFTER schema_update.sql has been applied.
-- ============================================================

-- Add password reset token columns
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) DEFAULT NULL AFTER activation_token;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token_expiry DATETIME DEFAULT NULL AFTER reset_token;
