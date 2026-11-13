<?php
// scratch/test_trust_dispute.php
// Integration test to verify trust score changes when disputes are resolved.

require_once __DIR__ . '/../config/db.php';

echo "=== STARTING TRUST SCORE DISPUTE IMPACT TEST ===\n";

$txn_id = null;
$dispute_id = null;

try {
    // 1. Establish Baselines
    echo "\n1. Establishing baseline trust scores...\n";
    
    // Get Buyer Aishah (user_id = 1)
    $stmt = $pdo->prepare("SELECT user_id, full_name, trust_score FROM users WHERE user_id = 1");
    $stmt->execute();
    $buyer = $stmt->fetch();
    
    // Get Seller Haziq (user_id = 2)
    $stmt = $pdo->prepare("SELECT user_id, full_name, trust_score FROM users WHERE user_id = 2");
    $stmt->execute();
    $seller = $stmt->fetch();
    
    echo "  Buyer: {$buyer['full_name']} | Trust Score: {$buyer['trust_score']}\n";
    echo "  Seller: {$seller['full_name']} | Trust Score: {$seller['trust_score']}\n";
    
    $baseline_buyer_score = (float)$buyer['trust_score'];
    $baseline_seller_score = (float)$seller['trust_score'];

    // 2. Create a mock transaction in completed state
    echo "\n2. Creating mock transaction (status='Selesai')...\n";
    $stmt = $pdo->prepare("
        INSERT INTO transactions (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, status)
        VALUES (1, 2, 1, 1, 150.00, 'Tunai', 'Selesai')
    ");
    $stmt->execute();
    $txn_id = $pdo->lastInsertId();
    echo "  Mock transaction created with ID: $txn_id\n";

    // Recalculate baseline after adding transaction (but before dispute)
    $pdo->prepare("CALL recalculate_trust_score(2)")->execute();
    $pdo->prepare("CALL recalculate_buyer_trust_score(1)")->execute();
    
    $pre_dispute_buyer = (float)$pdo->query("SELECT trust_score FROM users WHERE user_id = 1")->fetchColumn();
    $pre_dispute_seller = (float)$pdo->query("SELECT trust_score FROM users WHERE user_id = 2")->fetchColumn();
    echo "  Baseline updated (with completed transaction):\n";
    echo "    Buyer Trust: $pre_dispute_buyer\n";
    echo "    Seller Trust: $pre_dispute_seller\n";

    // 3. Create an Open Dispute (status='Terbuka', resolution=NULL)
    echo "\n3. Creating open dispute (status='Terbuka', resolution=NULL)...\n";
    $stmt = $pdo->prepare("
        INSERT INTO disputes (transaction_id, filed_by, title, description, severity, status, resolution)
        VALUES (?, 1, 'Barang Rosak', 'Skrin retak', 'Sederhana', 'Terbuka', NULL)
    ");
    $stmt->execute([$txn_id]);
    $dispute_id = $pdo->lastInsertId();
    echo "  Dispute created with ID: $dispute_id\n";
    
    // Verify scores (should not change yet since resolution is NULL)
    $curr_buyer = (float)$pdo->query("SELECT trust_score FROM users WHERE user_id = 1")->fetchColumn();
    $curr_seller = (float)$pdo->query("SELECT trust_score FROM users WHERE user_id = 2")->fetchColumn();
    echo "  Scores with Open Dispute:\n";
    echo "    Buyer Trust: $curr_buyer (Expected: $pre_dispute_buyer)\n";
    echo "    Seller Trust: $curr_seller (Expected: $pre_dispute_seller)\n";
    if ($curr_buyer == $pre_dispute_buyer && $curr_seller == $pre_dispute_seller) {
        echo "  ✅ Open dispute did NOT affect trust scores.\n";
    } else {
        echo "  ❌ ERROR: Open dispute altered trust scores!\n";
    }

    // 4. Resolve Dispute in Favor of Buyer (resolution = 'Memihak Pembeli')
    echo "\n4. Resolving dispute in favor of Buyer (resolution='Memihak Pembeli')...\n";
    $stmt = $pdo->prepare("
        UPDATE disputes 
           SET status = 'Selesai', resolution = 'Memihak Pembeli', resolved_by = 3, resolved_at = CURRENT_TIMESTAMP
         WHERE dispute_id = ?
    ");
    $stmt->execute([$dispute_id]);
    
    $curr_buyer = (float)$pdo->query("SELECT trust_score FROM users WHERE user_id = 1")->fetchColumn();
    $curr_seller = (float)$pdo->query("SELECT trust_score FROM users WHERE user_id = 2")->fetchColumn();
    echo "  Scores after In-Favor-of-Buyer Resolution:\n";
    echo "    Buyer Trust: $curr_buyer (Expected: $pre_dispute_buyer)\n";
    echo "    Seller Trust: $curr_seller (Expected: < $pre_dispute_seller)\n";
    
    if ($curr_buyer == $pre_dispute_buyer && $curr_seller < $pre_dispute_seller) {
        echo "  ✅ Success: Seller penalized by " . ($pre_dispute_seller - $curr_seller) . " points. Buyer unaffected.\n";
    } else {
        echo "  ❌ ERROR: Trust score adjustments incorrect!\n";
    }

    // 5. Resolve Dispute in Favor of Seller (resolution = 'Memihak Penjual')
    echo "\n5. Resolving dispute in favor of Seller (resolution='Memihak Penjual')...\n";
    $stmt = $pdo->prepare("
        UPDATE disputes 
           SET status = 'Selesai', resolution = 'Memihak Penjual', resolved_by = 3, resolved_at = CURRENT_TIMESTAMP
         WHERE dispute_id = ?
    ");
    $stmt->execute([$dispute_id]);
    
    $curr_buyer = (float)$pdo->query("SELECT trust_score FROM users WHERE user_id = 1")->fetchColumn();
    $curr_seller = (float)$pdo->query("SELECT trust_score FROM users WHERE user_id = 2")->fetchColumn();
    echo "  Scores after In-Favor-of-Seller Resolution:\n";
    echo "    Buyer Trust: $curr_buyer (Expected: < $pre_dispute_buyer)\n";
    echo "    Seller Trust: $curr_seller (Expected: $pre_dispute_seller)\n";
    
    if ($curr_buyer < $pre_dispute_buyer && $curr_seller == $pre_dispute_seller) {
        echo "  ✅ Success: Buyer penalized by " . ($pre_dispute_buyer - $curr_buyer) . " points. Seller restored/unaffected.\n";
    } else {
        echo "  ❌ ERROR: Trust score adjustments incorrect!\n";
    }

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
} finally {
    // 6. Cleanup
    echo "\n=== CLEANING UP DATABASE ===\n";
    if ($dispute_id) {
        $pdo->prepare("DELETE FROM disputes WHERE dispute_id = ?")->execute([$dispute_id]);
        echo "Deleted dispute $dispute_id\n";
    }
    if ($txn_id) {
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([$txn_id]);
        echo "Deleted transaction $txn_id\n";
    }
    
    // Restore baseline trust scores
    $pdo->prepare("UPDATE users SET trust_score = ? WHERE user_id = 1")->execute([$baseline_buyer_score]);
    $pdo->prepare("UPDATE users SET trust_score = ? WHERE user_id = 2")->execute([$baseline_seller_score]);
    echo "Restored buyer and seller to original scores.\n";
}
