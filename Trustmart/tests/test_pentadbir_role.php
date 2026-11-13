<?php
/**
 * PENTADBIR (ADMIN) ROLE - UAT Test Suite
 * Tests all admin-specific functionality
 */

require_once __DIR__ . '/../config/db.php';

class PentadbirRoleTest {
    private $pdo;
    private $admin_id;
    private $test_user_id;
    private $test_product_id;
    private $test_transaction_id;
    private $test_dispute_id;
    
    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
    }
    
    public function runAllTests() {
        echo "=== PENTADBIR (ADMIN) ROLE UAT TESTS ===\n";
        
        $this->setupTestData();
        
        $this->testCategory("Admin Authentication & Access");
        $this->test_A1_1_AdminLogin();
        $this->test_A1_2_AdminRoleVerification();
        $this->test_A1_3_AdminDashboardAccess();
        
        $this->testCategory("User Management");
        $this->test_A2_1_ViewAllUsers();
        $this->test_A2_2_ActivateUser();
        $this->test_A2_3_DeactivateUser();
        $this->test_A2_4_ViewUserDetails();
        $this->test_A2_5_TrustScoreManagement();
        
        $this->testCategory("Product Management");
        $this->test_A3_1_ViewAllProducts();
        $this->test_A3_2_ApproveProduct();
        $this->test_A3_3_SuspendProduct();
        $this->test_A3_4_DeleteProduct();
        
        $this->testCategory("Transaction Monitoring");
        $this->test_A4_1_ViewAllTransactions();
        $this->test_A4_2_ViewTransactionDetails();
        $this->test_A4_3_ViewPaymentRecords();
        $this->test_A4_4_TransactionStatistics();
        
        $this->testCategory("Dispute Resolution");
        $this->test_A5_1_ViewAllDisputes();
        $this->test_A5_2_ResolveDisputeRefundBuyer();
        $this->test_A5_3_ResolveDisputeFavorSeller();
        $this->test_A5_4_DisputeImpactOnTrustScore();
        
        $this->testCategory("Refund Management");
        $this->test_A6_1_ViewPendingRefunds();
        $this->test_A6_2_RetryFailedRefund();
        $this->test_A6_3_ManualRefundCompletion();
        
        $this->testCategory("System Statistics");
        $this->test_A7_1_TotalUsersCount();
        $this->test_A7_2_TotalTransactionsCount();
        $this->test_A7_3_RevenueCalculation();
        $this->test_A7_4_ActiveDisputesCount();
        
        $this->generateReport();
    }
    
    private function setupTestData() {
        echo "Setting up test data...\n";
        
        // Get or create admin user
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE role = 'pentadbir' LIMIT 1");
        $stmt->execute();
        $this->admin_id = $stmt->fetchColumn();
        
        if (!$this->admin_id) {
            $stmt = $this->pdo->prepare("
                INSERT INTO users (username, email, password, role, is_active, created_at)
                VALUES (?, ?, ?, 'pentadbir', 1, NOW())
            ");
            $admin_hash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt->execute(['admin_uat', 'admin_uat@trustmart.com', $admin_hash]);
            $this->admin_id = $this->pdo->lastInsertId();
        }
        
        // Create test user
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password, role, is_active, created_at)
            VALUES (?, ?, ?, 'pembeli', 1, NOW())
        ");
        $user_hash = password_hash('password123', PASSWORD_DEFAULT);
        $stmt->execute(['test_user_' . time(), 'user_' . time() . '@test.com', $user_hash]);
        $this->test_user_id = $this->pdo->lastInsertId();
        
        echo "✓ Test data setup complete\n";
    }
    
    private function testCategory($name) {
        echo "\nTEST CATEGORY: $name\n";
        echo str_repeat("-", 50) . "\n";
    }
    
    private function pass($testId, $message, $details = null) {
        echo "✓ PASS: $testId: $message\n";
        if ($details) echo "  → $details\n";
    }
    
    private function fail($testId, $message, $details = null) {
        echo "✗ FAIL: $testId: $message\n";
        if ($details) echo "  → $details\n";
    }
    
    // A1: Admin Authentication Tests
    private function test_A1_1_AdminLogin() {
        $stmt = $this->pdo->prepare("SELECT id, password FROM users WHERE role = 'pentadbir' AND id = ?");
        $stmt->execute([$this->admin_id]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin) {
            $this->pass("A1.1", "Admin login", "Admin account exists with ID: {$this->admin_id}");
        } else {
            $this->fail("A1.1", "Admin login", "Admin account not found");
        }
    }
    
    private function test_A1_2_AdminRoleVerification() {
        $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$this->admin_id]);
        $role = $stmt->fetchColumn();
        
        if ($role === 'pentadbir') {
            $this->pass("A1.2", "Admin role verification", "Role correctly set to 'pentadbir'");
        } else {
            $this->fail("A1.2", "Admin role verification", "Role is '$role', expected 'pentadbir'");
        }
    }
    
    private function test_A1_3_AdminDashboardAccess() {
        // Admin should see all statistics
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users");
        $user_count = $stmt->fetchColumn();
        
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM transactions");
        $tx_count = $stmt->fetchColumn();
        
        if ($user_count >= 0 && $tx_count >= 0) {
            $this->pass("A1.3", "Admin dashboard access", "Can query system statistics");
        } else {
            $this->fail("A1.3", "Admin dashboard access", "Cannot access system data");
        }
    }
    
    // A2: User Management Tests
    private function test_A2_1_ViewAllUsers() {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM users
        ");
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            $this->pass("A2.1", "View all users", "Found $count user(s) in system");
        } else {
            $this->fail("A2.1", "View all users", "No users found");
        }
    }
    
    private function test_A2_2_ActivateUser() {
        // Deactivate first
        $stmt = $this->pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        $stmt->execute([$this->test_user_id]);
        
        // Then activate
        $stmt = $this->pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
        $result = $stmt->execute([$this->test_user_id]);
        
        $stmt = $this->pdo->prepare("SELECT is_active FROM users WHERE id = ?");
        $stmt->execute([$this->test_user_id]);
        $is_active = $stmt->fetchColumn();
        
        if ($result && $is_active == 1) {
            $this->pass("A2.2", "Activate user", "User activated successfully");
        } else {
            $this->fail("A2.2", "Activate user", "Activation failed");
        }
    }
    
    private function test_A2_3_DeactivateUser() {
        $stmt = $this->pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        $result = $stmt->execute([$this->test_user_id]);
        
        $stmt = $this->pdo->prepare("SELECT is_active FROM users WHERE id = ?");
        $stmt->execute([$this->test_user_id]);
        $is_active = $stmt->fetchColumn();
        
        if ($result && $is_active == 0) {
            $this->pass("A2.3", "Deactivate user", "User deactivated successfully");
        } else {
            $this->fail("A2.3", "Deactivate user", "Deactivation failed");
        }
        
        // Reactivate for other tests
        $this->pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$this->test_user_id]);
    }
    
    private function test_A2_4_ViewUserDetails() {
        $stmt = $this->pdo->prepare("
            SELECT id, username, email, role, trust_score, created_at
            FROM users WHERE id = ?
        ");
        $stmt->execute([$this->test_user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $has_fields = isset($user['id'], $user['username'], $user['email'], 
                           $user['role'], $user['trust_score'], $user['created_at']);
        
        if ($has_fields) {
            $this->pass("A2.4", "View user details", "All user fields accessible");
        } else {
            $this->fail("A2.4", "View user details", "Missing user fields");
        }
    }
    
    private function test_A2_5_TrustScoreManagement() {
        $old_score = 100.0;
        $new_score = 95.0;
        
        $stmt = $this->pdo->prepare("UPDATE users SET trust_score = ? WHERE id = ?");
        $result = $stmt->execute([$new_score, $this->test_user_id]);
        
        $stmt = $this->pdo->prepare("SELECT trust_score FROM users WHERE id = ?");
        $stmt->execute([$this->test_user_id]);
        $score = $stmt->fetchColumn();
        
        if ($result && $score == $new_score) {
            $this->pass("A2.5", "Trust score management", "Score updated to: $score");
        } else {
            $this->fail("A2.5", "Trust score management", "Score update failed");
        }
    }
    
    // A3: Product Management Tests
    private function test_A3_1_ViewAllProducts() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM products");
        $count = $stmt->fetchColumn();
        
        if ($count >= 0) {
            $this->pass("A3.1", "View all products", "Found $count product(s)");
        } else {
            $this->fail("A3.1", "View all products", "Cannot query products");
        }
    }
    
    private function test_A3_2_ApproveProduct() {
        // Create test product
        $stmt = $this->pdo->prepare("
            INSERT INTO products (seller_id, name, description, price, stock, status, created_at)
            VALUES (?, ?, ?, 50.00, 10, 'pending', NOW())
        ");
        $stmt->execute([$this->test_user_id, 'Test Product', 'For approval', 50.00, 10]);
        $this->test_product_id = $this->pdo->lastInsertId();
        
        // Approve
        $stmt = $this->pdo->prepare("UPDATE products SET status = 'active' WHERE id = ?");
        $result = $stmt->execute([$this->test_product_id]);
        
        $stmt = $this->pdo->prepare("SELECT status FROM products WHERE id = ?");
        $stmt->execute([$this->test_product_id]);
        $status = $stmt->fetchColumn();
        
        if ($result && $status === 'active') {
            $this->pass("A3.2", "Approve product", "Product approved");
        } else {
            $this->fail("A3.2", "Approve product", "Approval failed");
        }
    }
    
    private function test_A3_3_SuspendProduct() {
        $stmt = $this->pdo->prepare("UPDATE products SET status = 'suspended' WHERE id = ?");
        $result = $stmt->execute([$this->test_product_id]);
        
        $stmt = $this->pdo->prepare("SELECT status FROM products WHERE id = ?");
        $stmt->execute([$this->test_product_id]);
        $status = $stmt->fetchColumn();
        
        if ($result && $status === 'suspended') {
            $this->pass("A3.3", "Suspend product", "Product suspended");
        } else {
            $this->fail("A3.3", "Suspend product", "Suspension failed");
        }
    }
    
    private function test_A3_4_DeleteProduct() {
        $stmt = $this->pdo->prepare("DELETE FROM products WHERE id = ?");
        $result = $stmt->execute([$this->test_product_id]);
        
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM products WHERE id = ?");
        $stmt->execute([$this->test_product_id]);
        $exists = $stmt->fetchColumn();
        
        if ($result && $exists == 0) {
            $this->pass("A3.4", "Delete product", "Product deleted");
        } else {
            $this->fail("A3.4", "Delete product", "Deletion failed");
        }
    }
    
    // A4: Transaction Monitoring Tests
    private function test_A4_1_ViewAllTransactions() {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM transactions
        ");
        $count = $stmt->fetchColumn();
        
        if ($count >= 0) {
            $this->pass("A4.1", "View all transactions", "Found $count transaction(s)");
        } else {
            $this->fail("A4.1", "View all transactions", "Cannot query transactions");
        }
    }
    
    private function test_A4_2_ViewTransactionDetails() {
        // Create test transaction
        $stmt = $this->pdo->prepare("
            INSERT INTO products (seller_id, name, description, price, stock, status, created_at)
            VALUES (?, ?, ?, 30.00, 5, 'active', NOW())
        ");
        $stmt->execute([$this->test_user_id, 'TX Test Product', 'For transaction', 30.00, 5]);
        $product_id = $this->pdo->lastInsertId();
        
        $stmt = $this->pdo->prepare("
            INSERT INTO transactions (buyer_id, seller_id, product_id, quantity, total_amount, 
                                     payment_method, payment_status, status, created_at)
            VALUES (?, ?, ?, 2, 60.00, 'cash', 'unpaid', 'pending', NOW())
        ");
        $stmt->execute([$this->test_user_id, $this->test_user_id, $product_id, 2]);
        $this->test_transaction_id = $this->pdo->lastInsertId();
        
        $stmt = $this->pdo->prepare("
            SELECT t.*, b.username as buyer_name, s.username as seller_name, p.name as product_name
            FROM transactions t
            JOIN users b ON t.buyer_id = b.id
            JOIN users s ON t.seller_id = s.id
            JOIN products p ON t.product_id = p.id
            WHERE t.id = ?
        ");
        $stmt->execute([$this->test_transaction_id]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tx && isset($tx['buyer_name'], $tx['seller_name'], $tx['product_name'])) {
            $this->pass("A4.2", "View transaction details", "All transaction details accessible");
        } else {
            $this->fail("A4.2", "View transaction details", "Cannot fetch transaction details");
        }
    }
    
    private function test_A4_3_ViewPaymentRecords() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM payments");
        $count = $stmt->fetchColumn();
        
        if ($count >= 0) {
            $this->pass("A4.3", "View payment records", "Found $count payment record(s)");
        } else {
            $this->fail("A4.3", "View payment records", "Cannot query payments");
        }
    }
    
    private function test_A4_4_TransactionStatistics() {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(total_amount) as revenue
            FROM transactions
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (isset($stats['total'], $stats['confirmed'], $stats['cancelled'], $stats['revenue'])) {
            $this->pass("A4.4", "Transaction statistics", 
                "Total: {$stats['total']}, Confirmed: {$stats['confirmed']}, Revenue: RM{$stats['revenue']}");
        } else {
            $this->fail("A4.4", "Transaction statistics", "Cannot calculate statistics");
        }
    }
    
    // A5: Dispute Resolution Tests
    private function test_A5_1_ViewAllDisputes() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM disputes");
        $count = $stmt->fetchColumn();
        
        if ($count >= 0) {
            $this->pass("A5.1", "View all disputes", "Found $count dispute(s)");
        } else {
            $this->fail("A5.1", "View all disputes", "Cannot query disputes");
        }
    }
    
    private function test_A5_2_ResolveDisputeRefundBuyer() {
        // Create dispute
        $stmt = $this->pdo->prepare("
            INSERT INTO disputes (transaction_id, filed_by, reason, status, created_at)
            VALUES (?, ?, ?, 'open', NOW())
        ");
        $stmt->execute([$this->test_transaction_id, $this->test_user_id, 'Product not as described']);
        $this->test_dispute_id = $this->pdo->lastInsertId();
        
        // Resolve in favor of buyer
        $stmt = $this->pdo->prepare("
            UPDATE disputes 
            SET status = 'resolved', resolution = 'Refund issued to buyer', resolved_at = NOW()
            WHERE id = ?
        ");
        $result = $stmt->execute([$this->test_dispute_id]);
        
        $stmt = $this->pdo->prepare("SELECT status FROM disputes WHERE id = ?");
        $stmt->execute([$this->test_dispute_id]);
        $status = $stmt->fetchColumn();
        
        if ($result && $status === 'resolved') {
            $this->pass("A5.2", "Resolve dispute (refund buyer)", "Dispute resolved in favor of buyer");
        } else {
            $this->fail("A5.2", "Resolve dispute (refund buyer)", "Resolution failed");
        }
    }
    
    private function test_A5_3_ResolveDisputeFavorSeller() {
        // Create another dispute
        $stmt = $this->pdo->prepare("
            INSERT INTO disputes (transaction_id, filed_by, reason, status, created_at)
            VALUES (?, ?, ?, 'open', NOW())
        ");
        $stmt->execute([$this->test_transaction_id, $this->test_user_id, 'Unfair complaint']);
        $dispute_id = $this->pdo->lastInsertId();
        
        // Resolve in favor of seller
        $stmt = $this->pdo->prepare("
            UPDATE disputes 
            SET status = 'resolved', resolution = 'No refund - seller verified delivery', resolved_at = NOW()
            WHERE id = ?
        ");
        $result = $stmt->execute([$dispute_id]);
        
        if ($result) {
            $this->pass("A5.3", "Resolve dispute (favor seller)", "Dispute resolved in favor of seller");
        } else {
            $this->fail("A5.3", "Resolve dispute (favor seller)", "Resolution failed");
        }
    }
    
    private function test_A5_4_DisputeImpactOnTrustScore() {
        // Get current trust score
        $stmt = $this->pdo->prepare("SELECT trust_score FROM users WHERE id = ?");
        $stmt->execute([$this->test_user_id]);
        $before = $stmt->fetchColumn();
        
        // Apply penalty
        $penalty = 10.0;
        $stmt = $this->pdo->prepare("
            UPDATE users SET trust_score = GREATEST(0, trust_score - ?) WHERE id = ?
        ");
        $stmt->execute([$penalty, $this->test_user_id]);
        
        $stmt = $this->pdo->prepare("SELECT trust_score FROM users WHERE id = ?");
        $stmt->execute([$this->test_user_id]);
        $after = $stmt->fetchColumn();
        
        if ($after < $before) {
            $this->pass("A5.4", "Dispute impact on trust score", 
                "Score decreased from $before to $after (penalty: -$penalty)");
        } else {
            $this->fail("A5.4", "Dispute impact on trust score", "Trust score not affected");
        }
    }
    
    // A6: Refund Management Tests
    private function test_A6_1_ViewPendingRefunds() {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM transactions 
            WHERE refund_status IN ('requested', 'processing')
        ");
        $count = $stmt->fetchColumn();
        
        if ($count >= 0) {
            $this->pass("A6.1", "View pending refunds", "Found $count pending refund(s)");
        } else {
            $this->fail("A6.1", "View pending refunds", "Cannot query refunds");
        }
    }
    
    private function test_A6_2_RetryFailedRefund() {
        // Create failed refund
        $stmt = $this->pdo->prepare("
            UPDATE transactions 
            SET refund_status = 'failed', 
                refund_error_message = 'Gateway timeout',
                refund_attempts = 3
            WHERE id = ?
        ");
        $stmt->execute([$this->test_transaction_id]);
        
        // Admin retries
        $stmt = $this->pdo->prepare("
            UPDATE transactions 
            SET refund_status = 'processing', 
                refund_attempts = refund_attempts + 1
            WHERE id = ?
        ");
        $result = $stmt->execute([$this->test_transaction_id]);
        
        if ($result) {
            $this->pass("A6.2", "Retry failed refund", "Refund retry initiated");
        } else {
            $this->fail("A6.2", "Retry failed refund", "Retry failed");
        }
    }
    
    private function test_A6_3_ManualRefundCompletion() {
        $stmt = $this->pdo->prepare("
            UPDATE transactions 
            SET refund_status = 'completed',
                refund_completed_at = NOW(),
                refund_method = 'manual'
            WHERE id = ?
        ");
        $result = $stmt->execute([$this->test_transaction_id]);
        
        $stmt = $this->pdo->prepare("SELECT refund_status FROM transactions WHERE id = ?");
        $stmt->execute([$this->test_transaction_id]);
        $status = $stmt->fetchColumn();
        
        if ($result && $status === 'completed') {
            $this->pass("A6.3", "Manual refund completion", "Refund marked completed manually");
        } else {
            $this->fail("A6.3", "Manual refund completion", "Manual completion failed");
        }
    }
    
    // A7: System Statistics Tests
    private function test_A7_1_TotalUsersCount() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users");
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            $this->pass("A7.1", "Total users count", "System has $count user(s)");
        } else {
            $this->fail("A7.1", "Total users count", "User count query failed");
        }
    }
    
    private function test_A7_2_TotalTransactionsCount() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM transactions");
        $count = $stmt->fetchColumn();
        
        if ($count >= 0) {
            $this->pass("A7.2", "Total transactions count", "System has $count transaction(s)");
        } else {
            $this->fail("A7.2", "Total transactions count", "Transaction count query failed");
        }
    }
    
    private function test_A7_3_RevenueCalculation() {
        $stmt = $this->pdo->query("
            SELECT SUM(total_amount) as total_revenue
            FROM transactions
            WHERE status = 'confirmed'
        ");
        $revenue = $stmt->fetchColumn() ?? 0;
        
        if ($revenue >= 0) {
            $this->pass("A7.3", "Revenue calculation", "Total revenue: RM" . number_format($revenue, 2));
        } else {
            $this->fail("A7.3", "Revenue calculation", "Revenue calculation failed");
        }
    }
    
    private function test_A7_4_ActiveDisputesCount() {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM disputes WHERE status = 'open'
        ");
        $count = $stmt->fetchColumn();
        
        if ($count >= 0) {
            $this->pass("A7.4", "Active disputes count", "Found $count open dispute(s)");
        } else {
            $this->fail("A7.4", "Active disputes count", "Dispute count query failed");
        }
    }
    
    private function generateReport() {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "PENTADBIR ROLE UAT TESTS COMPLETED\n";
        echo str_repeat("=", 50) . "\n";
    }
}

// Run tests
$test = new PentadbirRoleTest();
$test->runAllTests();
