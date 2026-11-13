<?php
require_once __DIR__ . '/../config/db.php';

try {
    // Drop existing procedure
    $pdo->exec("DROP PROCEDURE IF EXISTS recalculate_trust_score");

    // Create new procedure with start-from-100 penalty-based formula
    $sql = "
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
    END;
    ";
    
    $pdo->exec($sql);
    echo "Stored procedure recalculate_trust_score updated successfully.\n";

    // Recalculate trust scores for all sellers
    $sellers = $pdo->query("SELECT user_id FROM users WHERE role = 'penjual'")->fetchAll();
    foreach ($sellers as $s) {
        $call = $pdo->prepare('CALL recalculate_trust_score(?)');
        $call->execute([$s['user_id']]);
        $call->closeCursor();

        $score_stmt = $pdo->prepare('SELECT trust_score FROM users WHERE user_id = ?');
        $score_stmt->execute([$s['user_id']]);
        $score = $score_stmt->fetchColumn();
        echo "Seller ID {$s['user_id']} recalculated trust score: {$score}\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
