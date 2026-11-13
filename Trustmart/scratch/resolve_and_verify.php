<?php
// scratch/resolve_and_verify.php
// Resolves the dispute created during browser E2E test and verifies trust score drops.

require_once __DIR__ . '/../config/db.php';

echo "=== RESOLVING DISPUTE & VERIFYING SCORE IMPACT ===\n";

try {
    // 1. Fetch current dispute
    $dispute = $pdo->query("SELECT dispute_id, transaction_id, status, resolution FROM disputes WHERE status = 'Terbuka' LIMIT 1")->fetch();
    if (!$dispute) {
        throw new Exception("No open dispute found to resolve.");
    }
    $dispute_id = $dispute['dispute_id'];
    $txn_id = $dispute['transaction_id'];
    echo "Found open dispute ID: $dispute_id for Transaction: $txn_id\n";

    // 2. Fetch baseline seller score before resolution
    $seller = $pdo->query("SELECT u.user_id, u.full_name, u.trust_score FROM users u JOIN transactions t ON t.seller_id = u.user_id WHERE t.transaction_id = $txn_id")->fetch();
    $seller_id = $seller['user_id'];
    echo "Seller: {$seller['full_name']} (ID: $seller_id) | Current Trust Score: {$seller['trust_score']}\n";

    // 3. Resolve dispute in favor of buyer (Memihak Pembeli)
    echo "Resolving dispute in favor of Buyer...\n";
    $stmt = $pdo->prepare("
        UPDATE disputes
           SET status = 'Selesai',
               resolution = 'Memihak Pembeli',
               resolved_by = 3,
               resolved_at = CURRENT_TIMESTAMP
         WHERE dispute_id = ?
    ");
    $stmt->execute([$dispute_id]);
    echo "Dispute resolved successfully.\n";

    // 4. Fetch updated seller score
    $updated_score = $pdo->query("SELECT trust_score FROM users WHERE user_id = $seller_id")->fetchColumn();
    echo "Seller Trust Score after resolution: $updated_score\n";

    if ($updated_score == 90.00) {
        echo "✅ SUCCESS: Seller trust score successfully dropped to 90.00!\n";
    } else {
        echo "❌ FAILED: Trust score is $updated_score (expected 90.00).\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
