-- ============================================================
-- config/schema_update_v3.sql
-- TrustMart Schema Update v3 — Transaction Integrity Fixes
-- Run this once after schema_update_v2.sql on an existing database.
-- Back up the database first. The unique-index step intentionally fails
-- when duplicate disputes already exist so they can be reviewed manually.
-- ============================================================

-- Keep the canonical schema and application value domains aligned.
ALTER TABLE products MODIFY COLUMN category ENUM(
  'Elektronik', 'Buku', 'Pakaian', 'Makanan', 'Perkhidmatan', 'Lain-lain'
) NOT NULL;

-- Older dumps did not contain the password-reset columns.
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) DEFAULT NULL AFTER activation_token;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token_expiry DATETIME DEFAULT NULL AFTER reset_token;

-- Enforce the one-dispute-per-transaction invariant without failing when
-- this named index is already present.
SET @uq_dispute_exists = (
  SELECT COUNT(*)
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'disputes'
     AND index_name = 'uq_dispute_per_txn'
);
SET @uq_dispute_sql = IF(
  @uq_dispute_exists = 0,
  'ALTER TABLE disputes ADD UNIQUE INDEX uq_dispute_per_txn (transaction_id)',
  'SELECT 1'
);
PREPARE uq_dispute_stmt FROM @uq_dispute_sql;
EXECUTE uq_dispute_stmt;
DEALLOCATE PREPARE uq_dispute_stmt;

-- Trust penalties apply only after an administrator closes a dispute.
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
  DECLARE v_response_time   INT DEFAULT 3600;
  DECLARE v_is_verified     TINYINT DEFAULT 0;

  SELECT COUNT(*) INTO v_num_reviews
    FROM reviews
   WHERE seller_id = p_seller_id;

  SELECT COUNT(*) INTO v_num_txns
    FROM transactions
   WHERE seller_id = p_seller_id
     AND status IN ('Disahkan', 'Selesai', 'Dibatalkan');

  IF v_num_reviews = 0 AND v_num_txns = 0 THEN
    SET v_trust_score = 0.00;
  ELSE
    SET v_trust_score = 100;

    IF v_num_reviews > 0 THEN
      SELECT COALESCE(AVG(rating), 0) INTO v_avg_rating
        FROM reviews
       WHERE seller_id = p_seller_id;
      SET v_trust_score = v_trust_score - ((5.00 - v_avg_rating) * 8);
    END IF;

    SELECT COUNT(*),
           COALESCE(SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END), 0)
      INTO v_total_orders, v_completed
      FROM transactions
     WHERE seller_id = p_seller_id
       AND status IN ('Selesai', 'Dibatalkan');

    IF v_total_orders > 0 THEN
      SET v_completion_rate = (v_completed / v_total_orders) * 100;
      SET v_trust_score = v_trust_score - ((100.00 - v_completion_rate) * 0.3);
    END IF;

    SELECT COALESCE(response_time_avg, 3600), is_verified
      INTO v_response_time, v_is_verified
      FROM users
     WHERE user_id = p_seller_id;

    IF v_response_time > 86400 THEN
      SET v_trust_score = v_trust_score - 16;
    ELSEIF v_response_time > 3600 THEN
      SET v_trust_score = v_trust_score - 8;
    END IF;

    IF v_is_verified = 0 THEN
      SET v_trust_score = v_trust_score - 10;
    END IF;

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

  UPDATE users
     SET trust_score = v_trust_score
   WHERE user_id = p_seller_id;
END //
DELIMITER ;

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

  -- Seller accounts may also buy (see schema_update_v4.sql). Keep their
  -- seller score instead of overwriting it with the buyer formula.
  SET v_role = (SELECT role FROM users WHERE user_id = p_buyer_id);
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

-- Use NULL-safe comparison so assigning or clearing a resolution always
-- triggers recalculation when appropriate.
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

-- Correct any scores calculated under the former provisional-penalty rule.
DROP PROCEDURE IF EXISTS _recalculate_all_trust_scores;
DELIMITER //
CREATE PROCEDURE _recalculate_all_trust_scores()
BEGIN
  DECLARE done   INT DEFAULT 0;
  DECLARE v_uid  INT UNSIGNED;
  DECLARE v_role VARCHAR(20);
  DECLARE users_cursor CURSOR FOR
    SELECT user_id, role
      FROM users
     WHERE role IN ('pembeli', 'penjual');
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  OPEN users_cursor;
  users_loop: LOOP
    FETCH users_cursor INTO v_uid, v_role;
    IF done THEN
      LEAVE users_loop;
    END IF;

    IF v_role = 'penjual' THEN
      CALL recalculate_trust_score(v_uid);
    ELSE
      CALL recalculate_buyer_trust_score(v_uid);
    END IF;
  END LOOP;
  CLOSE users_cursor;
END //
DELIMITER ;

CALL _recalculate_all_trust_scores();
DROP PROCEDURE IF EXISTS _recalculate_all_trust_scores;
