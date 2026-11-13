<?php
// scratch/db_apply_trigger_fixes.php
// Applies the NULL-safe after_dispute_update trigger fix and
// ensures the after_review_insert trigger exists in the live DB.
require_once __DIR__ . '/../config/db.php';

try {
    // ── Fix after_dispute_update (NULL-safe DECLARE variables) ──────
    $pdo->exec('DROP TRIGGER IF EXISTS after_dispute_update');
    $pdo->exec("
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
        END
    ");
    echo "✓ after_dispute_update trigger replaced (NULL-safe)\n";

    // ── Ensure after_review_insert exists ──────────────────────────
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE()
            AND TRIGGER_NAME = 'after_review_insert'"
    )->fetchColumn();

    if (!$exists) {
        $pdo->exec('DROP TRIGGER IF EXISTS after_review_insert');
        $pdo->exec("
            CREATE TRIGGER after_review_insert
            AFTER INSERT ON reviews
            FOR EACH ROW
            BEGIN
              CALL recalculate_trust_score(NEW.seller_id);
            END
        ");
        echo "✓ after_review_insert trigger created\n";
    } else {
        echo "✓ after_review_insert trigger already exists\n";
    }

    // ── Recalculate all buyer scores (backfill fix #10) ────────────
    echo "\nRecalculating buyer trust scores...\n";
    foreach ($pdo->query("SELECT user_id, full_name FROM users WHERE role = 'pembeli'")->fetchAll() as $b) {
        $call = $pdo->prepare('CALL recalculate_buyer_trust_score(?)');
        $call->execute([$b['user_id']]);
        $call->closeCursor();

        $score_stmt = $pdo->prepare('SELECT trust_score FROM users WHERE user_id = ?');
        $score_stmt->execute([$b['user_id']]);
        $score = $score_stmt->fetchColumn();
        echo "  {$b['full_name']}: {$score}\n";
    }

    echo "\n✅ All trigger fixes applied to live database.\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
