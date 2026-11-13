-- ============================================================
-- config/schema_update.sql
-- TrustMart Database Schema Update Script
-- Runs alterations, redefines seller trust score, defines
-- buyer trust score, and registers triggers for automatic updates.
-- ============================================================

-- 1. Alter users table to add new columns
-- Fix #3: Add in correct order so column positions are right.
-- verification_status goes AFTER is_verified, then is_active AFTER verification_status,
-- then activation_token AFTER is_active.
ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_status ENUM('Menunggu', 'Disahkan', 'Ditolak') NOT NULL DEFAULT 'Menunggu' AFTER is_verified;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_status;
ALTER TABLE users ADD COLUMN IF NOT EXISTS activation_token VARCHAR(64) DEFAULT NULL AFTER is_active;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) DEFAULT NULL AFTER activation_token;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token_expiry DATETIME DEFAULT NULL AFTER reset_token;

-- Keep application and database value domains aligned.
ALTER TABLE products MODIFY COLUMN category ENUM(
  'Elektronik', 'Buku', 'Pakaian', 'Makanan', 'Perkhidmatan', 'Lain-lain'
) NOT NULL;

-- Enforce one dispute per transaction at the database boundary. MariaDB's
-- IF NOT EXISTS keeps this safe when the migration is applied once already.
ALTER TABLE disputes ADD UNIQUE INDEX IF NOT EXISTS uq_dispute_per_txn (transaction_id);

-- 2. Update existing users to be active, and set verification status accordingly
UPDATE users SET is_active = 1;
UPDATE users SET verification_status = IF(is_verified = 1, 'Disahkan', 'Menunggu');

-- 3. Stored Procedure: recalculate_trust_score (Seller)
DROP PROCEDURE IF EXISTS recalculate_trust_score;

DELIMITER //

CREATE PROCEDURE recalculate_trust_score(IN p_seller_id INT UNSIGNED)
BEGIN
  DECLARE v_avg_rating      DECIMAL(4,2) DEFAULT 0;
  DECLARE v_total_orders    INT UNSIGNED DEFAULT 0;
  DECLARE v_completed       INT UNSIGNED DEFAULT 0;
  DECLARE v_completion_rate DECIMAL(5,2) DEFAULT 100;
  DECLARE v_trust_score     DECIMAL(5,2) DEFAULT 100;
  DECLARE v_num_reviews     INT UNSIGNED DEFAULT 0;
  DECLARE v_num_txns        INT UNSIGNED DEFAULT 0;
  DECLARE v_dispute_penalty DECIMAL(5,2) DEFAULT 0;

  SELECT COUNT(*) INTO v_num_reviews FROM reviews WHERE seller_id = p_seller_id;
  SELECT COUNT(*) INTO v_num_txns FROM transactions WHERE seller_id = p_seller_id AND status IN ('Disahkan', 'Selesai', 'Dibatalkan');

  IF v_num_reviews = 0 AND v_num_txns = 0 THEN
    SET v_trust_score = 0.00;
  ELSE
    SET v_trust_score = 100;

    -- 1. Rating Penalty (max 40 pts)
    IF v_num_reviews > 0 THEN
      SELECT COALESCE(AVG(rating), 0) INTO v_avg_rating FROM reviews WHERE seller_id = p_seller_id;
      SET v_trust_score = v_trust_score - ((5.00 - v_avg_rating) * 8);
    END IF;

    -- 2. Completion Rate Penalty (max 30 pts)
    SELECT COUNT(*),
           SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END)
      INTO v_total_orders, v_completed
      FROM transactions
     WHERE seller_id = p_seller_id
       AND status IN ('Selesai','Dibatalkan');

    IF v_total_orders > 0 THEN
      SET v_completion_rate = (v_completed / v_total_orders) * 100;
      SET v_trust_score = v_trust_score - ((100.00 - v_completion_rate) * 0.3);
    END IF;

    -- 3. Response Speed Penalty (max 20 pts)
    SELECT COALESCE(response_time_avg, 3600) INTO @resp_time FROM users WHERE user_id = p_seller_id;
    IF @resp_time > 86400 THEN
      SET v_trust_score = v_trust_score - 16;
    ELSEIF @resp_time > 3600 THEN
      SET v_trust_score = v_trust_score - 8;
    END IF;

    -- 4. PTU Verification Penalty (max 10 pts)
    SELECT is_verified INTO @is_verified FROM users WHERE user_id = p_seller_id;
    IF @is_verified = 0 THEN
      SET v_trust_score = v_trust_score - 10;
    END IF;

    -- 5. Disputes Penalty: -10 for each dispute resolved against seller (max -40)
    SELECT LEAST(COUNT(*) * 10, 40)
      INTO v_dispute_penalty
      FROM disputes d
      JOIN transactions t ON d.transaction_id = t.transaction_id
     WHERE t.seller_id = p_seller_id
       AND d.status = 'Selesai'
       AND d.resolution = 'Memihak Pembeli';
    SET v_trust_score = v_trust_score - v_dispute_penalty;

    SET v_trust_score = GREATEST(0, LEAST(100, v_trust_score));
  END IF;

  UPDATE users SET trust_score = v_trust_score
   WHERE user_id = p_seller_id;
END //

DELIMITER ;

-- 4. Stored Procedure: recalculate_buyer_trust_score (Buyer)
DROP PROCEDURE IF EXISTS recalculate_buyer_trust_score;

DELIMITER //

CREATE PROCEDURE recalculate_buyer_trust_score(IN p_buyer_id INT UNSIGNED)
BEGIN
  DECLARE v_total_orders    INT UNSIGNED DEFAULT 0;
  DECLARE v_completed       INT UNSIGNED DEFAULT 0;
  DECLARE v_completion_rate DECIMAL(5,2) DEFAULT 0;
  DECLARE v_dispute_penalty DECIMAL(5,2) DEFAULT 0;
  DECLARE v_trust_score     DECIMAL(5,2) DEFAULT 100;

  -- Check total completed/canceled orders for the buyer
  SELECT COUNT(*),
         SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END)
    INTO v_total_orders, v_completed
    FROM transactions
   WHERE buyer_id = p_buyer_id
     AND status IN ('Selesai','Dibatalkan');

  -- Component 1: Completion rate (max contribution = 60 pts)
  IF v_total_orders > 0 THEN
    SET v_completion_rate = (v_completed / v_total_orders) * 100;
  ELSE
    SET v_completion_rate = 100; -- Default 100% completion rate for new buyers
  END IF;

  -- Component 2: Dispute penalty (starts at 40, -10 for each resolved against them)
  SELECT LEAST(COUNT(*) * 10, 40)
    INTO v_dispute_penalty
    FROM disputes d
    JOIN transactions t ON d.transaction_id = t.transaction_id
   WHERE t.buyer_id = p_buyer_id
     AND d.status = 'Selesai'
     AND d.resolution = 'Memihak Penjual';

  -- Calculate buyer trust score (out of 100)
  SET v_trust_score = (v_completion_rate / 100 * 60) + (40 - v_dispute_penalty);
  SET v_trust_score = GREATEST(0, LEAST(100, v_trust_score));

  UPDATE users SET trust_score = v_trust_score
   WHERE user_id = p_buyer_id;
END //

DELIMITER ;

-- 5. Trigger: after_transaction_insert
DROP TRIGGER IF EXISTS after_transaction_insert;

DELIMITER //
CREATE TRIGGER after_transaction_insert
AFTER INSERT ON transactions
FOR EACH ROW
BEGIN
  CALL recalculate_trust_score(NEW.seller_id);
  CALL recalculate_buyer_trust_score(NEW.buyer_id);
END //
DELIMITER ;

-- 6. Trigger: after_transaction_update
DROP TRIGGER IF EXISTS after_transaction_update;

DELIMITER //
CREATE TRIGGER after_transaction_update
AFTER UPDATE ON transactions
FOR EACH ROW
BEGIN
  IF OLD.status <> NEW.status OR OLD.quantity <> NEW.quantity THEN
    CALL recalculate_trust_score(NEW.seller_id);
    CALL recalculate_buyer_trust_score(NEW.buyer_id);
  END IF;
END //
DELIMITER ;

-- 7. Trigger: after_dispute_update
-- Fix #2: Use DECLARE variables instead of session vars (@var) so the
-- SELECT INTO is NULL-safe — if the transaction row is missing, the
-- CALL is skipped rather than using a stale session variable.
DROP TRIGGER IF EXISTS after_dispute_update;

DELIMITER //
CREATE TRIGGER after_dispute_update
AFTER UPDATE ON disputes
FOR EACH ROW
BEGIN
  DECLARE v_buyer_id  INT UNSIGNED DEFAULT NULL;
  DECLARE v_seller_id INT UNSIGNED DEFAULT NULL;

  IF NOT (OLD.resolution <=> NEW.resolution) OR OLD.status <> NEW.status THEN
    SELECT buyer_id, seller_id
      INTO v_buyer_id, v_seller_id
      FROM transactions
     WHERE transaction_id = NEW.transaction_id
     LIMIT 1;

    IF v_buyer_id IS NOT NULL AND v_seller_id IS NOT NULL THEN
      CALL recalculate_trust_score(v_seller_id);
      CALL recalculate_buyer_trust_score(v_buyer_id);
    END IF;
  END IF;
END //
DELIMITER ;

-- 8. Trigger: after_review_insert (Fix #6 — was missing from this file)
-- Fires when a buyer submits a review; recalculates the seller's trust score.
DROP TRIGGER IF EXISTS after_review_insert;

DELIMITER //
CREATE TRIGGER after_review_insert
AFTER INSERT ON reviews
FOR EACH ROW
BEGIN
  CALL recalculate_trust_score(NEW.seller_id);
END //
DELIMITER ;

-- 9. Fix #10: Recalculate ALL existing buyer scores now that the procedure exists.
-- New buyers are handled at registration; this backfills existing rows.
-- (Sellers are already recalculated individually by the original migration.)
-- We loop over all buyers using a cursor-free approach via a helper procedure.
DROP PROCEDURE IF EXISTS _backfill_buyer_scores;

DELIMITER //
CREATE PROCEDURE _backfill_buyer_scores()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_uid INT UNSIGNED;
  DECLARE cur CURSOR FOR SELECT user_id FROM users WHERE role = 'pembeli';
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO v_uid;
    IF done THEN LEAVE read_loop; END IF;
    CALL recalculate_buyer_trust_score(v_uid);
  END LOOP;
  CLOSE cur;
END //
DELIMITER ;

CALL _backfill_buyer_scores();
DROP PROCEDURE IF EXISTS _backfill_buyer_scores;
