<?php
// ============================================================
// scratch/db_reset_demo.php
// Resets TrustMart database to the original demo/seed state.
//
// KEEPS:  Users 1-5 (Aishah, Haziq, PTU Admin, Nurul Ain, Siti Hajar)
//         Products 1-5 (Haziq's laptop, powerbank, casing; Nurul Ain's book; Siti Hajar's baju)
//
// REMOVES: Test users (ID 6+), all transactions, all reviews, all disputes,
//          any products added beyond the original 5
// ============================================================
require_once __DIR__ . '/../config/db.php';

try {
    // ── Disable FK checks so we can truncate/delete in any order ──
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    // 1. Delete all disputes
    $pdo->exec('DELETE FROM disputes');
    echo "✓ Cleared disputes\n";

    // 2. Delete all reviews
    $pdo->exec('DELETE FROM reviews');
    echo "✓ Cleared reviews\n";

    // 3. Delete all transactions
    $pdo->exec('DELETE FROM transactions');
    echo "✓ Cleared transactions\n";

    // 4. Remove any extra products (beyond original seed IDs 1-5)
    $pdo->exec("DELETE FROM products WHERE product_id NOT IN (1, 2, 3, 4, 5)");
    echo "✓ Removed extra products\n";

    // 5. Restore the 5 original products to 'Aktif'
    $pdo->exec("UPDATE products SET status = 'Aktif' WHERE product_id IN (1, 2, 3, 4, 5)");
    echo "✓ Restored original products to Aktif\n";

    // 6. Remove test users (ID 6 and above)
    $pdo->exec('DELETE FROM users WHERE user_id >= 6');
    echo "✓ Removed test users (ID 6+)\n";

    // 7. Fix Aishah (ID 1 — pembeli): should be active and verification_status = 'Disahkan'
    //    Buyers don't need PTU verification; 'Menunggu' was just a migration artifact.
    $pdo->exec("UPDATE users SET is_active = 1, activation_token = NULL,
                    verification_status = 'Disahkan', trust_score = 100.00
                WHERE user_id = 1");
    echo "✓ Fixed Aishah (buyer): is_active=1, verification=Disahkan, trust=100\n";

    // 8. Reset original sellers to verified state with is_active = 1
    $pdo->exec("UPDATE users SET is_active = 1, activation_token = NULL
                WHERE user_id IN (2, 3, 4, 5)");
    echo "✓ Set original users (2–5) is_active = 1\n";

    // 9. Recalculate trust scores for all sellers (scores may be stale)
    //    Trust score starts fresh since all transactions are gone.
    //    Verified sellers with no orders start at: 100 - 0 (rating) - 0 (completion) - 0 (response) - 0 (PTU verified bonus kept) - 0 (disputes) = 100
    //    But the formula only subtracts from 100, so verified + no data = 100. Correct.
    $sellers = $pdo->query("SELECT user_id FROM users WHERE role = 'penjual'")->fetchAll();
    foreach ($sellers as $s) {
        $pdo->prepare('CALL recalculate_trust_score(?)')->execute([$s['user_id']]);
        $score = $pdo->query("SELECT trust_score FROM users WHERE user_id = {$s['user_id']}")->fetchColumn();
        echo "  Seller ID {$s['user_id']} trust score recalculated: {$score}\n";
    }

    // 10. Recalculate trust scores for buyers
    $buyers = $pdo->query("SELECT user_id FROM users WHERE role = 'pembeli'")->fetchAll();
    foreach ($buyers as $b) {
        $pdo->prepare('CALL recalculate_buyer_trust_score(?)')->execute([$b['user_id']]);
        $score = $pdo->query("SELECT trust_score FROM users WHERE user_id = {$b['user_id']}")->fetchColumn();
        echo "  Buyer ID {$b['user_id']} trust score recalculated: {$score}\n";
    }

    // 11. Re-enable FK checks
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    echo "\n=== Final state ===\n";
    echo "\nUSERS:\n";
    foreach ($pdo->query("SELECT user_id, full_name, role, verification_status, is_active, trust_score FROM users ORDER BY user_id")->fetchAll() as $u) {
        echo "  ID:{$u['user_id']} | {$u['full_name']} | {$u['role']} | {$u['verification_status']} | active:{$u['is_active']} | trust:{$u['trust_score']}\n";
    }

    echo "\nPRODUCTS:\n";
    foreach ($pdo->query("SELECT product_id, seller_id, name, status FROM products ORDER BY product_id")->fetchAll() as $p) {
        echo "  ID:{$p['product_id']} | seller:{$p['seller_id']} | {$p['name']} | {$p['status']}\n";
    }

    echo "\n✅ Database reset to clean demo state.\n";

} catch (PDOException $e) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "❌ Error: " . $e->getMessage() . "\n";
}
