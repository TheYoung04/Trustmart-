-- ============================================================
-- TrustMart Smart Trust Score v3 (TM-TRUST-3.0)
--
-- Retune inspired by TikTok Shop's Shop Performance Score:
--   * Three operational pillars (fulfilment / service / quality)
--     plus an identity bonus, each a smoothed rate against a target.
--   * Rolling 90-day window; falls back to the full record when the
--     window holds no terminal events (eBay-style low-volume rule),
--     so recent behaviour leads but sparse history still scores.
--   * NO confidence multiplication: confidence stays a displayed
--     information label, never a discount on the score. A good seller
--     with three clean trades now scores ~90, not ~50.
--   * Small-sample safety kept via light Beta(2,1) priors.
--   * Manipulation resistance kept: per-counterparty evidence cap,
--     verified-purchase reviews only, at-fault cancellations only.
--   * Explainable: every pillar persisted as a named component.
--
-- Seller weights: fulfilment 40, service 30, quality 25, PTU identity 5.
-- Buyer weights:  completion 70, disputes 25, UTHM identity 5.
--
-- Targets MariaDB 10.4+; idempotent; triggers from v5 keep working
-- because the procedure names are unchanged.
-- ============================================================

SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ------------------------------------------------------------
-- Seller profile (TikTok-style pillars)
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS recalculate_trust_score;
DELIMITER //
CREATE PROCEDURE recalculate_trust_score(IN p_seller_id INT UNSIGNED)
proc: BEGIN
  DECLARE v_role VARCHAR(20) DEFAULT NULL;
  DECLARE v_is_verified TINYINT DEFAULT 0;

  DECLARE v_90 DATETIME;
  DECLARE v_cutoff DATETIME;
  DECLARE v_win_events INT UNSIGNED DEFAULT 0;
  DECLARE v_window_label VARCHAR(24) DEFAULT '90 hari terkini';

  DECLARE v_completed INT UNSIGNED DEFAULT 0;
  DECLARE v_seller_cancelled INT UNSIGNED DEFAULT 0;
  DECLARE v_fulfilment_metric DECIMAL(8,4) DEFAULT 0;
  DECLARE v_fulfilment_points DECIMAL(8,4) DEFAULT 0;

  DECLARE v_decisions INT UNSIGNED DEFAULT 0;
  DECLARE v_timely_decisions INT UNSIGNED DEFAULT 0;
  DECLARE v_response_metric DECIMAL(8,4) DEFAULT 0;
  DECLARE v_response_points DECIMAL(8,4) DEFAULT 0;
  DECLARE v_response_seconds INT DEFAULT NULL;

  DECLARE v_neg_reviews INT UNSIGNED DEFAULT 0;
  DECLARE v_adverse_disputes INT UNSIGNED DEFAULT 0;
  DECLARE v_defect_rate DECIMAL(10,6) DEFAULT 0;
  DECLARE v_quality_metric DECIMAL(8,4) DEFAULT 0;
  DECLARE v_quality_points DECIMAL(8,4) DEFAULT 0;
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

  -- Rolling window: 90 days, or the full record when the window is empty.
  SET v_90 = DATE_SUB(NOW(), INTERVAL 90 DAY);
  SELECT COUNT(*) INTO v_win_events
    FROM transactions
   WHERE seller_id = p_seller_id
     AND ((status = 'Selesai' AND completed_at >= v_90)
       OR (status = 'Dibatalkan' AND cancelled_by = 'seller' AND cancelled_at >= v_90));
  SET v_cutoff = IF(v_win_events > 0, v_90, '1970-01-01 00:00:00');
  SET v_window_label = IF(v_win_events > 0, '90 hari terkini', 'semua rekod');

  -- Pillar 1: fulfilment (40). Buyer-confirmed completions vs seller-caused
  -- cancellations; Beta(2,1) prior keeps one trade from looking conclusive.
  SELECT
    COALESCE(SUM(status = 'Selesai'), 0),
    COALESCE(SUM(status = 'Dibatalkan' AND cancelled_by = 'seller'), 0)
    INTO v_completed, v_seller_cancelled
    FROM transactions
   WHERE seller_id = p_seller_id
     AND ((status = 'Selesai' AND completed_at >= v_cutoff)
       OR (status = 'Dibatalkan' AND cancelled_by = 'seller' AND cancelled_at >= v_cutoff));
  SET v_fulfilment_metric =
    ((v_completed + 2.0000) / (v_completed + v_seller_cancelled + 3.0000)) * 100;
  SET v_fulfilment_points = 40.0000 * v_fulfilment_metric / 100.0000;

  -- Pillar 2: service (30). Order decisions within 24 hours (campus target).
  SELECT
    COUNT(*),
    COALESCE(SUM(TIMESTAMPDIFF(SECOND, created_at,
      CASE WHEN confirmed_at IS NOT NULL THEN confirmed_at ELSE cancelled_at END
    ) BETWEEN 0 AND 86400), 0),
    AVG(TIMESTAMPDIFF(SECOND, created_at,
      CASE WHEN confirmed_at IS NOT NULL THEN confirmed_at ELSE cancelled_at END
    ))
    INTO v_decisions, v_timely_decisions, v_response_seconds
    FROM transactions
   WHERE seller_id = p_seller_id
     AND ((confirmed_at IS NOT NULL AND confirmed_at >= v_cutoff)
       OR (status = 'Dibatalkan' AND cancelled_by = 'seller' AND cancelled_at >= v_cutoff));
  SET v_response_metric =
    ((v_timely_decisions + 2.0000) / (v_decisions + 3.0000)) * 100;
  SET v_response_points = 30.0000 * v_response_metric / 100.0000;

  -- Pillar 3: quality & safety (25). Negative reviews (1-2 stars, TikTok's
  -- NRR signal) plus upheld buyer disputes, smoothed; a 25% defect rate
  -- exhausts the pillar.
  SELECT COUNT(*) INTO v_neg_reviews
    FROM reviews rv
    JOIN transactions tx ON tx.transaction_id = rv.transaction_id
   WHERE rv.seller_id = p_seller_id
     AND rv.rating <= 2
     AND tx.status = 'Selesai'
     AND rv.created_at >= v_cutoff;

  SELECT COUNT(DISTINCT d.transaction_id) INTO v_adverse_disputes
    FROM disputes d
    JOIN transactions t ON t.transaction_id = d.transaction_id
   WHERE t.seller_id = p_seller_id
     AND d.status = 'Selesai'
     AND d.resolution = 'Memihak Pembeli'
     AND COALESCE(t.completed_at, t.cancelled_at) >= v_cutoff;

  SET v_defect_rate = (v_adverse_disputes + v_neg_reviews + 0.5000)
                    / (v_completed + v_seller_cancelled + 10.0000);
  SET v_quality_metric = 100.0000
    * (1.0000 - LEAST(1.0000, v_defect_rate / 0.2500));
  SET v_quality_points = 25.0000 * v_quality_metric / 100.0000;
  SET v_identity_points = IF(v_is_verified = 1, 5.0000, 0.0000);

  -- Confidence is INFORMATION ONLY in v3 (never multiplies the score).
  -- Evidence still capped at three terminal outcomes per counterparty.
  SELECT COUNT(*), COALESCE(SUM(LEAST(e.event_count, 3)), 0)
    INTO v_counterparties, v_evidence_units
    FROM (
      SELECT buyer_id, COUNT(*) AS event_count
        FROM transactions
       WHERE seller_id = p_seller_id
         AND ((status = 'Selesai' AND completed_at >= v_cutoff)
           OR (status = 'Dibatalkan' AND cancelled_by = 'seller' AND cancelled_at >= v_cutoff))
       GROUP BY buyer_id
    ) AS e;

  IF v_evidence_units > 0 THEN
    SET v_confidence = LEAST(100.0000,
      100.0000 * LN(1.0000 + v_evidence_units) / LN(11.0000));
  ELSE
    SET v_confidence = 0.0000;
  END IF;

  SET v_raw_score = v_fulfilment_points + v_response_points
                  + v_quality_points + v_identity_points;
  -- No confidence discount in v3, but zero evidence still stores 0 so
  -- brand-new accounts never look trusted on legacy surfaces.
  SET v_score = IF(v_evidence_units = 0, 0.0000,
                   GREATEST(0, LEAST(100, v_raw_score)));

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
    v_evidence_units, v_counterparties, v_completed, 'TM-TRUST-3.0'
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
    (p_seller_id,'penjual','fulfilment','Kebolehpercayaan pesanan',1,40,
     ROUND(v_fulfilment_metric,2),ROUND(v_fulfilment_points,2),
     v_completed + v_seller_cancelled,
     CONCAT(v_completed,' selesai; ',v_seller_cancelled,' dibatalkan penjual · ',v_window_label)),
    (p_seller_id,'penjual','service','Respons pantas',2,30,
     ROUND(v_response_metric,2),ROUND(v_response_points,2),v_decisions,
     CONCAT(v_timely_decisions,'/',v_decisions,' keputusan dalam 24 jam · ',v_window_label)),
    (p_seller_id,'penjual','quality','Kualiti & keselamatan',3,25,
     ROUND(v_quality_metric,2),ROUND(v_quality_points,2),
     v_neg_reviews + v_adverse_disputes,
     CONCAT(v_neg_reviews,' ulasan negatif; ',v_adverse_disputes,' aduan memihak pembeli · ',v_window_label)),
    (p_seller_id,'penjual','identity','Pengesahan identiti PTU',4,5,
     IF(v_is_verified=1,100,0),ROUND(v_identity_points,2),v_is_verified,
     IF(v_is_verified=1,'Penjual disahkan PTU','Penjual belum disahkan PTU'));

  UPDATE users
     SET trust_score=ROUND(v_score,2), response_time_avg=v_response_seconds
   WHERE user_id=p_seller_id;
END //
DELIMITER ;

-- ------------------------------------------------------------
-- Buyer profile (same philosophy: completion / safety / identity)
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS recalculate_buyer_trust_score;
DELIMITER //
CREATE PROCEDURE recalculate_buyer_trust_score(IN p_buyer_id INT UNSIGNED)
proc: BEGIN
  DECLARE v_role VARCHAR(20) DEFAULT NULL;
  DECLARE v_is_active TINYINT DEFAULT 0;

  DECLARE v_90 DATETIME;
  DECLARE v_cutoff DATETIME;
  DECLARE v_win_events INT UNSIGNED DEFAULT 0;
  DECLARE v_window_label VARCHAR(24) DEFAULT '90 hari terkini';

  DECLARE v_completed INT UNSIGNED DEFAULT 0;
  DECLARE v_buyer_cancelled INT UNSIGNED DEFAULT 0;
  DECLARE v_completion_metric DECIMAL(8,4) DEFAULT 0;
  DECLARE v_completion_points DECIMAL(8,4) DEFAULT 0;
  DECLARE v_adverse_disputes INT UNSIGNED DEFAULT 0;
  DECLARE v_defect_rate DECIMAL(10,6) DEFAULT 0;
  DECLARE v_dispute_metric DECIMAL(8,4) DEFAULT 0;
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

  SET v_90 = DATE_SUB(NOW(), INTERVAL 90 DAY);
  SELECT COUNT(*) INTO v_win_events
    FROM transactions
   WHERE buyer_id=p_buyer_id
     AND ((status='Selesai' AND completed_at >= v_90)
       OR (status='Dibatalkan' AND cancelled_by='buyer' AND cancelled_at >= v_90));
  SET v_cutoff = IF(v_win_events > 0, v_90, '1970-01-01 00:00:00');
  SET v_window_label = IF(v_win_events > 0, '90 hari terkini', 'semua rekod');

  SELECT
    COALESCE(SUM(status='Selesai'),0),
    COALESCE(SUM(status='Dibatalkan' AND cancelled_by='buyer'),0)
    INTO v_completed, v_buyer_cancelled
    FROM transactions
   WHERE buyer_id=p_buyer_id
     AND ((status='Selesai' AND completed_at >= v_cutoff)
       OR (status='Dibatalkan' AND cancelled_by='buyer' AND cancelled_at >= v_cutoff));
  SET v_completion_metric =
    ((v_completed + 2.0000) / (v_completed + v_buyer_cancelled + 3.0000)) * 100;
  SET v_completion_points = 70.0000 * v_completion_metric / 100.0000;

  SELECT COUNT(DISTINCT d.transaction_id) INTO v_adverse_disputes
    FROM disputes d
    JOIN transactions t ON t.transaction_id=d.transaction_id
   WHERE t.buyer_id=p_buyer_id
     AND d.status='Selesai'
     AND d.resolution='Memihak Penjual'
     AND COALESCE(t.completed_at, t.cancelled_at) >= v_cutoff;
  SET v_defect_rate = (v_adverse_disputes + 0.5000)
                    / (v_completed + v_buyer_cancelled + 10.0000);
  SET v_dispute_metric = 100.0000
    * (1.0000 - LEAST(1.0000, v_defect_rate / 0.2500));
  SET v_dispute_points = 25.0000 * v_dispute_metric / 100.0000;
  SET v_identity_points = IF(v_is_active=1, 5.0000, 0.0000);

  SELECT COUNT(*), COALESCE(SUM(LEAST(e.event_count,3)),0)
    INTO v_counterparties, v_evidence_units
    FROM (
      SELECT seller_id, COUNT(*) AS event_count
        FROM transactions
       WHERE buyer_id=p_buyer_id
         AND ((status='Selesai' AND completed_at >= v_cutoff)
           OR (status='Dibatalkan' AND cancelled_by='buyer' AND cancelled_at >= v_cutoff))
       GROUP BY seller_id
    ) AS e;

  IF v_evidence_units > 0 THEN
    SET v_confidence = LEAST(100.0000,
      100.0000 * LN(1.0000 + v_evidence_units) / LN(11.0000));
  ELSE
    SET v_confidence = 0.0000;
  END IF;

  SET v_raw_score = v_completion_points + v_dispute_points + v_identity_points;
  SET v_score = IF(v_evidence_units = 0, 0.0000,
                   GREATEST(0, LEAST(100, v_raw_score)));

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
    user_id,profile_role,score,raw_score,confidence,confidence_label,
    trust_level,evidence_units,distinct_counterparties,
    completed_transactions,formula_version
  ) VALUES (
    p_buyer_id,'pembeli',ROUND(v_score,2),ROUND(v_raw_score,2),
    ROUND(v_confidence,2),v_confidence_label,v_level,v_evidence_units,
    v_counterparties,v_completed,'TM-TRUST-3.0'
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
    (p_buyer_id,'pembeli','completion','Penyelesaian pesanan',1,70,
     ROUND(v_completion_metric,2),ROUND(v_completion_points,2),
     v_completed + v_buyer_cancelled,
     CONCAT(v_completed,' selesai; ',v_buyer_cancelled,' dibatalkan pembeli · ',v_window_label)),
    (p_buyer_id,'pembeli','disputes','Keselamatan pertikaian',2,25,
     ROUND(v_dispute_metric,2),ROUND(v_dispute_points,2),v_adverse_disputes,
     CONCAT(v_adverse_disputes,' keputusan aduan memihak penjual · ',v_window_label)),
    (p_buyer_id,'pembeli','identity','Identiti UTHM aktif',3,5,
     IF(v_is_active=1,100,0),ROUND(v_identity_points,2),v_is_active,
     IF(v_is_active=1,'Akaun UTHM aktif','Akaun tidak aktif'));

  UPDATE users
     SET buyer_trust_score=ROUND(v_score,2),
         trust_score=IF(role='pembeli',ROUND(v_score,2),trust_score)
   WHERE user_id=p_buyer_id;
END //
DELIMITER ;

-- ------------------------------------------------------------
-- Rebuild every profile under v3
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS _tm_backfill_trust_v3;
DELIMITER //
CREATE PROCEDURE _tm_backfill_trust_v3()
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
CALL _tm_backfill_trust_v3();
DROP PROCEDURE IF EXISTS _tm_backfill_trust_v3;

SET SQL_MODE = @OLD_SQL_MODE;