-- ============================================================
-- TrustMart Smart Trust Score v2 (TM-TRUST-2.0)
--
-- Design goals:
--   * Explainable: every point is persisted as a named component.
--   * Evidence-aware: no history means score 0, not a perfect score.
--   * Small-sample safe: Bayesian priors limit one-review volatility.
--   * Manipulation-resistant: each counterparty contributes at most
--     three evidence units to confidence.
--   * Fair: only the party that cancels receives a fulfilment failure.
--   * Auditable: role-specific buyer/seller profiles coexist.
--
-- Seller weights: rating 35, fulfilment 30, disputes 20,
--                 response 10, PTU identity 5.
-- Buyer weights:  completion 65, disputes 25, UTHM identity 10.
--
-- This migration targets MariaDB 10.4+ and is safe to run again.
-- Back up the database before applying it.
-- ============================================================

SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- Immutable transaction lifecycle evidence. Legacy terminal records are
-- marked as legacy rather than attributing blame to either party.
ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS confirmed_at TIMESTAMP NULL DEFAULT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP NULL DEFAULT NULL AFTER confirmed_at,
  ADD COLUMN IF NOT EXISTS completed_by ENUM('buyer','seller','system','legacy') NULL DEFAULT NULL AFTER completed_at,
  ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP NULL DEFAULT NULL AFTER completed_by,
  ADD COLUMN IF NOT EXISTS cancelled_by ENUM('buyer','seller','admin','system','legacy') NULL DEFAULT NULL AFTER cancelled_at;

UPDATE transactions
   SET confirmed_at = COALESCE(confirmed_at, updated_at)
 WHERE status = 'Disahkan';

UPDATE transactions
   SET completed_at = COALESCE(completed_at, updated_at),
       completed_by = COALESCE(completed_by, 'legacy')
 WHERE status = 'Selesai';

UPDATE transactions
   SET cancelled_at = COALESCE(cancelled_at, updated_at),
       cancelled_by = COALESCE(cancelled_by, 'legacy')
 WHERE status = 'Dibatalkan';

-- Compatibility mirror retained while older pages transition to role-specific
-- trust_profiles. New accounts must never start as perfectly trusted.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS buyer_trust_score DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER trust_score;

-- One summary row per user and marketplace role. A seller may also buy, so
-- storing only users.trust_score cannot represent both reputations.
CREATE TABLE IF NOT EXISTS trust_profiles (
  user_id                  INT UNSIGNED NOT NULL,
  profile_role             ENUM('pembeli','penjual') NOT NULL,
  score                    DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  raw_score                DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  confidence               DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  confidence_label         ENUM('Tiada','Rendah','Sederhana','Tinggi') NOT NULL DEFAULT 'Tiada',
  trust_level              ENUM('Baharu','Awal','Membina','Sederhana','Dipercayai','Sangat Dipercayai') NOT NULL DEFAULT 'Baharu',
  evidence_units           DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  distinct_counterparties  INT UNSIGNED NOT NULL DEFAULT 0,
  completed_transactions   INT UNSIGNED NOT NULL DEFAULT 0,
  formula_version          VARCHAR(24) NOT NULL DEFAULT 'TM-TRUST-2.0',
  calculated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, profile_role),
  KEY idx_trust_profile_rank (profile_role, score, confidence),
  CONSTRAINT fk_trust_profile_user
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Components are typed rows rather than UI-side arithmetic. The modal and
-- database therefore always show the same formula and numbers.
CREATE TABLE IF NOT EXISTS trust_score_components (
  user_id          INT UNSIGNED NOT NULL,
  profile_role     ENUM('pembeli','penjual') NOT NULL,
  component_code   VARCHAR(32) NOT NULL,
  component_label  VARCHAR(100) NOT NULL,
  component_order  TINYINT UNSIGNED NOT NULL,
  max_points       DECIMAL(5,2) NOT NULL,
  metric_value     DECIMAL(6,2) NOT NULL,
  points           DECIMAL(5,2) NOT NULL,
  evidence_count   INT UNSIGNED NOT NULL DEFAULT 0,
  details          VARCHAR(255) NOT NULL,
  PRIMARY KEY (user_id, profile_role, component_code),
  CONSTRAINT fk_trust_component_profile
    FOREIGN KEY (user_id, profile_role)
    REFERENCES trust_profiles(user_id, profile_role)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Composite indexes keep trigger-time recalculation bounded as data grows.
SET @idx_seller_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'transactions'
     AND index_name = 'idx_txn_seller_trust'
);
SET @idx_seller_sql = IF(@idx_seller_exists = 0,
  'ALTER TABLE transactions ADD INDEX idx_txn_seller_trust (seller_id, status, cancelled_by)',
  'SELECT 1');
PREPARE idx_seller_stmt FROM @idx_seller_sql;
EXECUTE idx_seller_stmt;
DEALLOCATE PREPARE idx_seller_stmt;

SET @idx_buyer_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'transactions'
     AND index_name = 'idx_txn_buyer_trust'
);
SET @idx_buyer_sql = IF(@idx_buyer_exists = 0,
  'ALTER TABLE transactions ADD INDEX idx_txn_buyer_trust (buyer_id, status, cancelled_by)',
  'SELECT 1');
PREPARE idx_buyer_stmt FROM @idx_buyer_sql;
EXECUTE idx_buyer_stmt;
DEALLOCATE PREPARE idx_buyer_stmt;

-- ------------------------------------------------------------
-- Seller profile
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS recalculate_trust_score;
DELIMITER //
CREATE PROCEDURE recalculate_trust_score(IN p_seller_id INT UNSIGNED)
proc: BEGIN
  DECLARE v_role VARCHAR(20) DEFAULT NULL;
  DECLARE v_is_verified TINYINT DEFAULT 0;

  DECLARE v_reviewers INT UNSIGNED DEFAULT 0;
  DECLARE v_review_sum DECIMAL(12,4) DEFAULT 0;
  DECLARE v_bayesian_rating DECIMAL(8,4) DEFAULT 4.0000;
  DECLARE v_rating_metric DECIMAL(8,4) DEFAULT 75.0000;
  DECLARE v_rating_points DECIMAL(8,4) DEFAULT 0;

  DECLARE v_completed INT UNSIGNED DEFAULT 0;
  DECLARE v_seller_cancelled INT UNSIGNED DEFAULT 0;
  DECLARE v_fulfilment_trials INT UNSIGNED DEFAULT 0;
  DECLARE v_fulfilment_metric DECIMAL(8,4) DEFAULT 80.0000;
  DECLARE v_fulfilment_points DECIMAL(8,4) DEFAULT 0;

  DECLARE v_adverse_disputes INT UNSIGNED DEFAULT 0;
  DECLARE v_defect_rate DECIMAL(10,6) DEFAULT 0.050000;
  DECLARE v_dispute_metric DECIMAL(8,4) DEFAULT 80.0000;
  DECLARE v_dispute_points DECIMAL(8,4) DEFAULT 0;

  DECLARE v_decisions INT UNSIGNED DEFAULT 0;
  DECLARE v_timely_decisions INT UNSIGNED DEFAULT 0;
  DECLARE v_response_metric DECIMAL(8,4) DEFAULT 80.0000;
  DECLARE v_response_points DECIMAL(8,4) DEFAULT 0;
  DECLARE v_response_seconds INT DEFAULT NULL;
  DECLARE v_identity_points DECIMAL(8,4) DEFAULT 0;

  DECLARE v_counterparties INT UNSIGNED DEFAULT 0;
  DECLARE v_evidence_units DECIMAL(8,2) DEFAULT 0;
  DECLARE v_confidence DECIMAL(8,4) DEFAULT 0;
  DECLARE v_confidence_label VARCHAR(20) DEFAULT 'Tiada';
  DECLARE v_raw_score DECIMAL(8,4) DEFAULT 0;
  DECLARE v_score DECIMAL(8,4) DEFAULT 0;
  DECLARE v_level VARCHAR(32) DEFAULT 'Baharu';

  SELECT role, is_verified INTO v_role, v_is_verified
    FROM users WHERE user_id = p_seller_id LIMIT 1;
  IF v_role IS NULL OR v_role <> 'penjual' THEN
    LEAVE proc;
  END IF;

  -- A buyer contributes at most one averaged rating, so repeated trades with
  -- the same account cannot dominate the rating component.
  SELECT COUNT(*), COALESCE(SUM(r.buyer_average), 0)
    INTO v_reviewers, v_review_sum
    FROM (
      SELECT rv.buyer_id, AVG(rv.rating) AS buyer_average
        FROM reviews rv
        JOIN transactions tx ON tx.transaction_id = rv.transaction_id
       WHERE rv.seller_id = p_seller_id
         AND tx.status = 'Selesai'
       GROUP BY rv.buyer_id
    ) AS r;

  -- Bayesian rating prior: 4.0 stars with strength 5. Map 1..5 stars to
  -- 0..100 quality, preventing one five-star review from looking conclusive.
  SET v_bayesian_rating = (20.0000 + v_review_sum) / (5 + v_reviewers);
  SET v_rating_metric = GREATEST(0, LEAST(100,
    ((v_bayesian_rating - 1.0000) / 4.0000) * 100));
  SET v_rating_points = 35.0000 * v_rating_metric / 100.0000;

  SELECT
    COALESCE(SUM(status = 'Selesai'), 0),
    COALESCE(SUM(status = 'Dibatalkan' AND cancelled_by = 'seller'), 0)
    INTO v_completed, v_seller_cancelled
    FROM transactions
   WHERE seller_id = p_seller_id;

  SET v_fulfilment_trials = v_completed + v_seller_cancelled;
  -- Beta(8,2) prior: a transparent 80% starting estimate which is replaced
  -- progressively by buyer-confirmed completions and seller-caused failures.
  SET v_fulfilment_metric =
    ((v_completed + 8.0000) / (v_fulfilment_trials + 10.0000)) * 100;
  SET v_fulfilment_points = 30.0000 * v_fulfilment_metric / 100.0000;

  SELECT COUNT(DISTINCT d.transaction_id)
    INTO v_adverse_disputes
    FROM disputes d
    JOIN transactions t ON t.transaction_id = d.transaction_id
   WHERE t.seller_id = p_seller_id
     AND d.status = 'Selesai'
     AND d.resolution = 'Memihak Pembeli';

  -- Smoothed upheld-case rate over eligible outcomes. A 25% defect rate
  -- exhausts this component; resolved neutral/favourable cases do not punish.
  SET v_defect_rate = (v_adverse_disputes + 0.5000)
                    / (v_fulfilment_trials + 10.0000);
  SET v_dispute_metric = 100.0000
    * (1.0000 - LEAST(1.0000, v_defect_rate / 0.2500));
  SET v_dispute_points = 20.0000 * v_dispute_metric / 100.0000;

  SELECT
    COUNT(*),
    COALESCE(SUM(TIMESTAMPDIFF(SECOND, created_at,
      CASE WHEN confirmed_at IS NOT NULL THEN confirmed_at ELSE cancelled_at END
    ) BETWEEN 0 AND 43200), 0),
    AVG(TIMESTAMPDIFF(SECOND, created_at,
      CASE WHEN confirmed_at IS NOT NULL THEN confirmed_at ELSE cancelled_at END
    ))
    INTO v_decisions, v_timely_decisions, v_response_seconds
    FROM transactions
   WHERE seller_id = p_seller_id
     AND (confirmed_at IS NOT NULL
       OR (status = 'Dibatalkan' AND cancelled_by = 'seller' AND cancelled_at IS NOT NULL));

  -- 12-hour response target; Beta(4,1) prior avoids both zero and perfect
  -- claims when response evidence is sparse.
  SET v_response_metric =
    ((v_timely_decisions + 4.0000) / (v_decisions + 5.0000)) * 100;
  SET v_response_points = 10.0000 * v_response_metric / 100.0000;
  SET v_identity_points = IF(v_is_verified = 1, 5.0000, 0.0000);

  -- Confidence evidence is capped at three terminal outcomes per buyer.
  -- Multiple genuine counterparties therefore matter more than one partner.
  SELECT COUNT(*), COALESCE(SUM(LEAST(e.event_count, 3)), 0)
    INTO v_counterparties, v_evidence_units
    FROM (
      SELECT buyer_id, COUNT(*) AS event_count
        FROM transactions
       WHERE seller_id = p_seller_id
         AND (status = 'Selesai'
           OR (status = 'Dibatalkan' AND cancelled_by = 'seller'))
       GROUP BY buyer_id
    ) AS e;

  IF v_evidence_units > 0 THEN
    SET v_confidence = LEAST(100.0000,
      100.0000 * LN(1.0000 + v_evidence_units) / LN(11.0000));
  ELSE
    SET v_confidence = 0.0000;
  END IF;

  SET v_raw_score = v_rating_points + v_fulfilment_points
                  + v_dispute_points + v_response_points
                  + v_identity_points;
  SET v_score = IF(v_evidence_units = 0, 0.0000,
                   v_raw_score * v_confidence / 100.0000);
  SET v_score = GREATEST(0, LEAST(100, v_score));

  SET v_confidence_label = CASE
    WHEN v_confidence = 0 THEN 'Tiada'
    WHEN v_confidence < 40 THEN 'Rendah'
    WHEN v_confidence < 80 THEN 'Sederhana'
    ELSE 'Tinggi'
  END;
  SET v_level = CASE
    WHEN v_evidence_units = 0 THEN 'Baharu'
    WHEN v_confidence < 40 THEN 'Awal'
    WHEN v_score < 50 THEN 'Membina'
    WHEN v_score < 70 THEN 'Sederhana'
    WHEN v_score < 85 THEN 'Dipercayai'
    ELSE 'Sangat Dipercayai'
  END;

  INSERT INTO trust_profiles (
    user_id, profile_role, score, raw_score, confidence,
    confidence_label, trust_level, evidence_units,
    distinct_counterparties, completed_transactions, formula_version
  ) VALUES (
    p_seller_id, 'penjual', ROUND(v_score,2), ROUND(v_raw_score,2),
    ROUND(v_confidence,2), v_confidence_label, v_level,
    v_evidence_units, v_counterparties, v_completed, 'TM-TRUST-2.0'
  )
  ON DUPLICATE KEY UPDATE
    score=VALUES(score), raw_score=VALUES(raw_score),
    confidence=VALUES(confidence), confidence_label=VALUES(confidence_label),
    trust_level=VALUES(trust_level), evidence_units=VALUES(evidence_units),
    distinct_counterparties=VALUES(distinct_counterparties),
    completed_transactions=VALUES(completed_transactions),
    formula_version=VALUES(formula_version), calculated_at=CURRENT_TIMESTAMP;

  DELETE FROM trust_score_components
   WHERE user_id=p_seller_id AND profile_role='penjual';
  INSERT INTO trust_score_components
    (user_id, profile_role, component_code, component_label,
     component_order, max_points, metric_value, points, evidence_count, details)
  VALUES
    (p_seller_id,'penjual','rating','Ulasan pembelian disahkan',1,35,
     ROUND(v_rating_metric,2),ROUND(v_rating_points,2),v_reviewers,
     CONCAT(v_reviewers,' pembeli unik; purata Bayesian ',ROUND(v_bayesian_rating,2),'/5')),
    (p_seller_id,'penjual','fulfilment','Kebolehpercayaan pesanan',2,30,
     ROUND(v_fulfilment_metric,2),ROUND(v_fulfilment_points,2),v_fulfilment_trials,
     CONCAT(v_completed,' selesai; ',v_seller_cancelled,' dibatalkan penjual')),
    (p_seller_id,'penjual','disputes','Keselamatan pertikaian',3,20,
     ROUND(v_dispute_metric,2),ROUND(v_dispute_points,2),v_adverse_disputes,
     CONCAT(v_adverse_disputes,' keputusan aduan memihak pembeli')),
    (p_seller_id,'penjual','response','Respons keputusan pesanan',4,10,
     ROUND(v_response_metric,2),ROUND(v_response_points,2),v_decisions,
     CONCAT(v_timely_decisions,'/',v_decisions,' keputusan dalam 12 jam')),
    (p_seller_id,'penjual','identity','Pengesahan identiti PTU',5,5,
     IF(v_is_verified=1,100,0),ROUND(v_identity_points,2),v_is_verified,
     IF(v_is_verified=1,'Penjual disahkan PTU','Penjual belum disahkan PTU'));

  -- Compatibility mirror for existing integrations while reads migrate to
  -- trust_profiles. The seller profile remains the primary score for sellers.
  UPDATE users
     SET trust_score=ROUND(v_score,2), response_time_avg=v_response_seconds
   WHERE user_id=p_seller_id;
END //
DELIMITER ;

-- ------------------------------------------------------------
-- Buyer profile (also created for seller accounts that shop)
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS recalculate_buyer_trust_score;
DELIMITER //
CREATE PROCEDURE recalculate_buyer_trust_score(IN p_buyer_id INT UNSIGNED)
proc: BEGIN
  DECLARE v_role VARCHAR(20) DEFAULT NULL;
  DECLARE v_is_active TINYINT DEFAULT 0;
  DECLARE v_completed INT UNSIGNED DEFAULT 0;
  DECLARE v_buyer_cancelled INT UNSIGNED DEFAULT 0;
  DECLARE v_completion_trials INT UNSIGNED DEFAULT 0;
  DECLARE v_completion_metric DECIMAL(8,4) DEFAULT 80.0000;
  DECLARE v_completion_points DECIMAL(8,4) DEFAULT 0;
  DECLARE v_adverse_disputes INT UNSIGNED DEFAULT 0;
  DECLARE v_defect_rate DECIMAL(10,6) DEFAULT 0.050000;
  DECLARE v_dispute_metric DECIMAL(8,4) DEFAULT 80.0000;
  DECLARE v_dispute_points DECIMAL(8,4) DEFAULT 0;
  DECLARE v_identity_points DECIMAL(8,4) DEFAULT 0;
  DECLARE v_counterparties INT UNSIGNED DEFAULT 0;
  DECLARE v_evidence_units DECIMAL(8,2) DEFAULT 0;
  DECLARE v_confidence DECIMAL(8,4) DEFAULT 0;
  DECLARE v_confidence_label VARCHAR(20) DEFAULT 'Tiada';
  DECLARE v_raw_score DECIMAL(8,4) DEFAULT 0;
  DECLARE v_score DECIMAL(8,4) DEFAULT 0;
  DECLARE v_level VARCHAR(32) DEFAULT 'Baharu';

  SELECT role, is_active INTO v_role, v_is_active
    FROM users WHERE user_id=p_buyer_id LIMIT 1;
  IF v_role IS NULL OR v_role NOT IN ('pembeli','penjual') THEN
    LEAVE proc;
  END IF;

  SELECT
    COALESCE(SUM(status='Selesai'),0),
    COALESCE(SUM(status='Dibatalkan' AND cancelled_by='buyer'),0)
    INTO v_completed, v_buyer_cancelled
    FROM transactions
   WHERE buyer_id=p_buyer_id;

  SET v_completion_trials=v_completed+v_buyer_cancelled;
  SET v_completion_metric=
    ((v_completed+8.0000)/(v_completion_trials+10.0000))*100;
  SET v_completion_points=65.0000*v_completion_metric/100.0000;

  SELECT COUNT(DISTINCT d.transaction_id)
    INTO v_adverse_disputes
    FROM disputes d
    JOIN transactions t ON t.transaction_id=d.transaction_id
   WHERE t.buyer_id=p_buyer_id
     AND d.status='Selesai'
     AND d.resolution='Memihak Penjual';

  SET v_defect_rate=(v_adverse_disputes+0.5000)
                   /(v_completion_trials+10.0000);
  SET v_dispute_metric=100.0000
    *(1.0000-LEAST(1.0000,v_defect_rate/0.2500));
  SET v_dispute_points=25.0000*v_dispute_metric/100.0000;
  SET v_identity_points=IF(v_is_active=1,10.0000,0.0000);

  SELECT COUNT(*), COALESCE(SUM(LEAST(e.event_count,3)),0)
    INTO v_counterparties, v_evidence_units
    FROM (
      SELECT seller_id, COUNT(*) AS event_count
        FROM transactions
       WHERE buyer_id=p_buyer_id
         AND (status='Selesai'
           OR (status='Dibatalkan' AND cancelled_by='buyer'))
       GROUP BY seller_id
    ) AS e;

  IF v_evidence_units>0 THEN
    SET v_confidence=LEAST(100.0000,
      100.0000*LN(1.0000+v_evidence_units)/LN(11.0000));
  ELSE
    SET v_confidence=0.0000;
  END IF;

  SET v_raw_score=v_completion_points+v_dispute_points+v_identity_points;
  SET v_score=IF(v_evidence_units=0,0.0000,
                 v_raw_score*v_confidence/100.0000);
  SET v_score=GREATEST(0,LEAST(100,v_score));
  SET v_confidence_label=CASE
    WHEN v_confidence=0 THEN 'Tiada'
    WHEN v_confidence<40 THEN 'Rendah'
    WHEN v_confidence<80 THEN 'Sederhana'
    ELSE 'Tinggi'
  END;
  SET v_level=CASE
    WHEN v_evidence_units=0 THEN 'Baharu'
    WHEN v_confidence<40 THEN 'Awal'
    WHEN v_score<50 THEN 'Membina'
    WHEN v_score<70 THEN 'Sederhana'
    WHEN v_score<85 THEN 'Dipercayai'
    ELSE 'Sangat Dipercayai'
  END;

  INSERT INTO trust_profiles (
    user_id,profile_role,score,raw_score,confidence,confidence_label,
    trust_level,evidence_units,distinct_counterparties,
    completed_transactions,formula_version
  ) VALUES (
    p_buyer_id,'pembeli',ROUND(v_score,2),ROUND(v_raw_score,2),
    ROUND(v_confidence,2),v_confidence_label,v_level,v_evidence_units,
    v_counterparties,v_completed,'TM-TRUST-2.0'
  )
  ON DUPLICATE KEY UPDATE
    score=VALUES(score),raw_score=VALUES(raw_score),
    confidence=VALUES(confidence),confidence_label=VALUES(confidence_label),
    trust_level=VALUES(trust_level),evidence_units=VALUES(evidence_units),
    distinct_counterparties=VALUES(distinct_counterparties),
    completed_transactions=VALUES(completed_transactions),
    formula_version=VALUES(formula_version),calculated_at=CURRENT_TIMESTAMP;

  DELETE FROM trust_score_components
   WHERE user_id=p_buyer_id AND profile_role='pembeli';
  INSERT INTO trust_score_components
    (user_id,profile_role,component_code,component_label,component_order,
     max_points,metric_value,points,evidence_count,details)
  VALUES
    (p_buyer_id,'pembeli','completion','Penyelesaian pesanan',1,65,
     ROUND(v_completion_metric,2),ROUND(v_completion_points,2),v_completion_trials,
     CONCAT(v_completed,' selesai; ',v_buyer_cancelled,' dibatalkan pembeli')),
    (p_buyer_id,'pembeli','disputes','Keselamatan pertikaian',2,25,
     ROUND(v_dispute_metric,2),ROUND(v_dispute_points,2),v_adverse_disputes,
     CONCAT(v_adverse_disputes,' keputusan aduan memihak penjual')),
    (p_buyer_id,'pembeli','identity','Identiti UTHM aktif',3,10,
     IF(v_is_active=1,100,0),ROUND(v_identity_points,2),v_is_active,
     IF(v_is_active=1,'Akaun UTHM aktif','Akaun tidak aktif'));

  -- Keep the compatibility buyer mirror for pages not migrated yet. Seller
  -- accounts retain their seller score in users.trust_score.
  UPDATE users
     SET buyer_trust_score=ROUND(v_score,2),
         trust_score=IF(role='pembeli',ROUND(v_score,2),trust_score)
   WHERE user_id=p_buyer_id;
END //
DELIMITER ;

-- ------------------------------------------------------------
-- Recalculation coverage
-- ------------------------------------------------------------
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

DROP TRIGGER IF EXISTS after_transaction_update;
DELIMITER //
CREATE TRIGGER after_transaction_update
AFTER UPDATE ON transactions
FOR EACH ROW
BEGIN
  IF OLD.status<>NEW.status
     OR OLD.quantity<>NEW.quantity
     OR NOT (OLD.confirmed_at<=>NEW.confirmed_at)
     OR NOT (OLD.completed_at<=>NEW.completed_at)
     OR NOT (OLD.completed_by<=>NEW.completed_by)
     OR NOT (OLD.cancelled_at<=>NEW.cancelled_at)
     OR NOT (OLD.cancelled_by<=>NEW.cancelled_by) THEN
    CALL recalculate_trust_score(NEW.seller_id);
    CALL recalculate_buyer_trust_score(NEW.buyer_id);
  END IF;
END //
DELIMITER ;

DROP TRIGGER IF EXISTS after_transaction_delete;
DELIMITER //
CREATE TRIGGER after_transaction_delete
AFTER DELETE ON transactions
FOR EACH ROW
BEGIN
  CALL recalculate_trust_score(OLD.seller_id);
  CALL recalculate_buyer_trust_score(OLD.buyer_id);
END //
DELIMITER ;

DROP TRIGGER IF EXISTS after_review_insert;
DELIMITER //
CREATE TRIGGER after_review_insert
AFTER INSERT ON reviews
FOR EACH ROW BEGIN
  CALL recalculate_trust_score(NEW.seller_id);
END //
DELIMITER ;

DROP TRIGGER IF EXISTS after_review_update;
DELIMITER //
CREATE TRIGGER after_review_update
AFTER UPDATE ON reviews
FOR EACH ROW BEGIN
  CALL recalculate_trust_score(NEW.seller_id);
  IF OLD.seller_id<>NEW.seller_id THEN
    CALL recalculate_trust_score(OLD.seller_id);
  END IF;
END //
DELIMITER ;

DROP TRIGGER IF EXISTS after_review_delete;
DELIMITER //
CREATE TRIGGER after_review_delete
AFTER DELETE ON reviews
FOR EACH ROW BEGIN
  CALL recalculate_trust_score(OLD.seller_id);
END //
DELIMITER ;

DROP TRIGGER IF EXISTS after_dispute_update;
DELIMITER //
CREATE TRIGGER after_dispute_update
AFTER UPDATE ON disputes
FOR EACH ROW
BEGIN
  DECLARE v_buyer_id INT UNSIGNED DEFAULT NULL;
  DECLARE v_seller_id INT UNSIGNED DEFAULT NULL;
  IF NOT (OLD.resolution<=>NEW.resolution) OR OLD.status<>NEW.status THEN
    SELECT buyer_id,seller_id INTO v_buyer_id,v_seller_id
      FROM transactions WHERE transaction_id=NEW.transaction_id LIMIT 1;
    IF v_buyer_id IS NOT NULL AND v_seller_id IS NOT NULL THEN
      CALL recalculate_trust_score(v_seller_id);
      CALL recalculate_buyer_trust_score(v_buyer_id);
    END IF;
  END IF;
END //
DELIMITER ;

DROP TRIGGER IF EXISTS after_dispute_delete;
DELIMITER //
CREATE TRIGGER after_dispute_delete
AFTER DELETE ON disputes
FOR EACH ROW
BEGIN
  DECLARE v_buyer_id INT UNSIGNED DEFAULT NULL;
  DECLARE v_seller_id INT UNSIGNED DEFAULT NULL;
  SELECT buyer_id,seller_id INTO v_buyer_id,v_seller_id
    FROM transactions WHERE transaction_id=OLD.transaction_id LIMIT 1;
  IF v_buyer_id IS NOT NULL AND v_seller_id IS NOT NULL THEN
    CALL recalculate_trust_score(v_seller_id);
    CALL recalculate_buyer_trust_score(v_buyer_id);
  END IF;
END //
DELIMITER ;

-- Build both role profiles. Sellers receive a buyer profile too because a
-- single UTHM account may shop from another seller.
DROP PROCEDURE IF EXISTS _tm_backfill_trust_v2;
DELIMITER //
CREATE PROCEDURE _tm_backfill_trust_v2()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_uid INT UNSIGNED;
  DECLARE v_role VARCHAR(20);
  DECLARE cur CURSOR FOR
    SELECT user_id,role FROM users WHERE role IN ('pembeli','penjual');
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done=1;
  OPEN cur;
  loop_users: LOOP
    FETCH cur INTO v_uid,v_role;
    IF done THEN LEAVE loop_users; END IF;
    CALL recalculate_buyer_trust_score(v_uid);
    IF v_role='penjual' THEN CALL recalculate_trust_score(v_uid); END IF;
  END LOOP;
  CLOSE cur;
END //
DELIMITER ;
CALL _tm_backfill_trust_v2();
DROP PROCEDURE IF EXISTS _tm_backfill_trust_v2;

SET SQL_MODE = @OLD_SQL_MODE;
