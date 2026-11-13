-- ============================================================
-- config/schema_update_v4.sql
-- TrustMart Schema Update v4: seller accounts may also buy.
--
-- UTHM issues one email per student, so one account must be able to both
-- sell and buy. The users table has a single trust_score column; for seller
-- accounts it holds the seller score. The transaction and dispute triggers
-- call recalculate_buyer_trust_score() for every buyer, which would replace
-- a shopping seller's score with the buyer formula.
--
-- This update makes recalculate_buyer_trust_score() role-aware:
--   pembeli -> buyer formula (same formula as schema_update_v3.sql)
--   penjual -> recalculates the seller score instead
--   other   -> no change
--
-- Safe to run more than once. Triggers do not need to change.
-- ============================================================

SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

DROP PROCEDURE IF EXISTS recalculate_buyer_trust_score;
DELIMITER //
CREATE PROCEDURE recalculate_buyer_trust_score(IN p_buyer_id INT UNSIGNED)
proc: BEGIN
  DECLARE v_role            VARCHAR(20)  DEFAULT NULL;
  DECLARE v_total_orders    INT UNSIGNED DEFAULT 0;
  DECLARE v_completed       INT UNSIGNED DEFAULT 0;
  DECLARE v_completion_rate DECIMAL(5,2) DEFAULT 0;
  DECLARE v_dispute_penalty DECIMAL(5,2) DEFAULT 0;
  DECLARE v_trust_score     DECIMAL(5,2) DEFAULT 100;

  SET v_role = (SELECT role FROM users WHERE user_id = p_buyer_id);

  -- A seller who shops keeps their seller score.
  IF v_role = 'penjual' THEN
    CALL recalculate_trust_score(p_buyer_id);
    LEAVE proc;
  END IF;

  IF v_role IS NULL OR v_role <> 'pembeli' THEN
    LEAVE proc;
  END IF;

  SELECT COUNT(*),
         COALESCE(SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END), 0)
    INTO v_total_orders, v_completed
    FROM transactions
   WHERE buyer_id = p_buyer_id
     AND status IN ('Selesai', 'Dibatalkan');

  IF v_total_orders > 0 THEN
    SET v_completion_rate = (v_completed / v_total_orders) * 100;
  ELSE
    SET v_completion_rate = 100;
  END IF;

  SELECT LEAST(COUNT(*) * 10, 40)
    INTO v_dispute_penalty
    FROM disputes d
    JOIN transactions t ON d.transaction_id = t.transaction_id
   WHERE t.buyer_id = p_buyer_id
     AND d.status = 'Selesai'
     AND d.resolution = 'Memihak Penjual';

  SET v_trust_score = (v_completion_rate / 100 * 60) + (40 - v_dispute_penalty);
  SET v_trust_score = GREATEST(0, LEAST(100, v_trust_score));

  UPDATE users
     SET trust_score = v_trust_score
   WHERE user_id = p_buyer_id;
END //
DELIMITER ;

SET SQL_MODE = @OLD_SQL_MODE;
