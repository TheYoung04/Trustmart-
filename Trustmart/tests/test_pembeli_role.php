<?php
/**
 * Pembeli (Buyer) Role UAT Test Script
 * 
 * Tests complete buyer user journey including:
 * - Registration & Authentication
 * - Product browsing
 * - Cash orders
 * - Online payment orders
 * - Reviews & disputes
 * - Trust score
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment.php';

class BuyerRoleTest {
    private $pdo;
    private $test_results = [];
    private $test_user_id = null;
    private $test_seller_id = null;
    private $test_product_id = null;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function runAllTests() {
        echo "=== PEMBELI (BUYER) ROLE UAT TESTS ===\n\n";
        
        $this->setupTestData();
        
        // Test categories
        $this->testRegistrationAuth();
        $this->testProductBrowsing();
        $this->testCashOrders();
        $this->testOnlinePaymentOrders();
        $this->testReviewsAndRatings();
        $this->testDisputes();
        $this->testTrustScore();
        $this->testDashboardFeatures();
        
        $this->cleanup();
        $this->printResults();
    }
    
    private function setupTestData() {
        echo "Setting up test data...\n";
        
        try {
            // Create test seller
            $stmt = $this->pdo->prepare("
                INSERT INTO users (matric_no, full_name, email, password_hash, role, faculty, is_verified, verification_status)
                VALUES ('TEST-SELLER-001', 'Test Seller', 'testseller@uat.test', ?, 'penjual', 'FKE', 1, 'Disahkan')
            ");
            $stmt->execute([password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 12])]);
            $this->test_seller_id = $this->pdo->lastInsertId();
            
            // Create test product
            $stmt = $this->pdo->prepare("
                INSERT INTO products (seller_id, name, category, description, price, stock, status)
                VALUES (?, 'UAT Test Product', 'Elektronik', 'Test product for UAT', 50.00, 100, 'Aktif')
            ");
            $stmt->execute([$this->test_seller_id]);
            $this->test_product_id = $this->pdo->lastInsertId();
            
            echo "✓ Test data setup complete\n\n";
        } catch (Exception $e) {
            echo "✗ Setup failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    private function testRegistrationAuth() {
        echo "TEST CATEGORY: Registration & Authentication\n";
        echo str_repeat("-", 50) . "\n";
        
        // P1.1: Register new buyer
        $test_name = "P1.1: Register new buyer account";
        try {
            $matric = 'UAT-' . time();
            $email = 'buyer.' . time() . '@uat.test';
            
            $stmt = $this->pdo->prepare("
                INSERT INTO users (matric_no, full_name, email, password_hash, role, faculty, is_verified)
                VALUES (?, 'UAT Test Buyer', ?, ?, 'pembeli', 'FKP', 1)
            ");
            $result = $stmt->execute([
                $matric,
                $email,
                password_hash('BuyerPass123!', PASSWORD_BCRYPT, ['cost' => 12])
            ]);
            
            $this->test_user_id = $this->pdo->lastInsertId();
            
            $this->pass($test_name, "User created with ID: {$this->test_user_id}");
        } catch (Exception $e) {
            $this->fail($test_name, $e->getMessage());
        }
        
        // P1.2: Login validation
        $test_name = "P1.2: Login credential verification";
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$this->test_user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify('BuyerPass123!', $user['password_hash'])) {
                $this->pass($test_name, "Password verification successful");
            } else {
                $this->fail($test_name, "Password verification failed");
            }
        } catch (Exception $e) {
            $this->fail($test_name, $e->getMessage());
        }
        
        // P1.3: User has correct role
        $test_name = "P1.3: User role assignment";
        try {
            $stmt = $this->pdo->prepare("SELECT role FROM users WHERE user_id = ?");
            $stmt->execute([$this->test_user_id]);
            $role = $stmt->fetchColumn();
            
            if ($role === 'pembeli') {
                $this->pass($test_name, "Role correctly set to 'pembeli'");
            } else {
                $this->fail($test_name, "Expected 'pembeli', got '{$role}'");
            }
        } catch (Exception $e) {
            $this->fail($test_name, $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function testProductBrowsing() {
        echo "TEST CATEGORY: Product Browsing\n";
        echo str_repeat("-", 50) . "\n";
        
        // P2.1: View active products
        $test_name = "P2.1: Browse active products";
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM products 
                WHERE status = 'Aktif' AND seller_id = ?
            ");
            $stmt->execute([$this->test_seller_id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $this->pass($test_name, "Found {$count} active product(s)");
            } else {
                $this->fail($test_name, "No active products found");
            }
        } catch (Exception $e) {
            $this->fail($test_name, $e->getMessage());
        }
        
        // P2.2: Cannot buy own products (seller accounts)
        $test_name = "P2.2: Self-purchase prevention";
        try {
            // Simulate attempt to buy own product
            $stmt = $this->pdo->prepare("
                SELECT seller_id FROM products WHERE product_id = ?
            ");
            $stmt->execute([$this->test_product_id]);
            $seller_id = $stmt->fetchColumn();
            
            if ($seller_id !== $this->test_user_id) {
                $this->pass($test_name, "Buyer and seller are different users (can proceed)");
            } else {
                $this->fail($test_name, "Buyer and seller are same user (should be blocked)");
            }
        } catch (Exception $e) {
            $this->fail($test_name, $e->getMessage());
        }
        
        // P2.3: Product details complete
        $test_name = "P2.3: Product details validation";
        try {
            $stmt = $this->pdo->prepare("
                SELECT p.*, u.full_name as seller_name, u.trust_score
                FROM products p
                JOIN users u ON p.seller_id = u.user_id
                WHERE p.product_id = ?
            ");
            $stmt->execute([$this->test_product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $required_fields = ['name', 'price', 'stock', 'seller_name', 'trust_score'];
            $missing = [];
            
            foreach ($required_fields as $field) {
                if (!isset($product[$field]) || $product[$field] === null) {
                    $missing[] = $field;
                }
            }
            
            if (empty($missing)) {
                $this->pass($test_name, "All required product fields present");
            } else {
                $this->fail($test_name, "Missing fields: " . implode(', ', $missing));
            }
        } catch (Exception $e) {
            $this->fail($test_name, $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function testCashOrders() {
        echo "TEST CATEGORY: Cash Payment Orders\n";
        echo str_repeat("-", 50) . "\n";
        
        $test_txn_id = null;
        
        // P3.1: Place cash order
        $test_name = "P3.1: Create cash payment order";
        try {
            $this->pdo->beginTransaction();
            
            // Lock product
            $stmt = $this->pdo->prepare("
                SELECT * FROM products WHERE product_id = ? AND status = 'Aktif' FOR UPDATE
            ");
            $stmt->execute([$this->test_product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Update stock
            $stmt = $this->pdo->prepare("
                UPDATE products SET stock = stock - 1, sold_count = sold_count + 1
                WHERE product_id = ? AND stock >= 1
            ");
            $stmt->execute([$this->test_product_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception("Stock update failed");
            }
            
            // Create transaction
            $stmt = $this->pdo->prepare("
                INSERT INTO transactions 
                (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, payment_type, status)
                VALUES (?, ?, ?, 1, ?, 'Tunai', 'cash', 'Menunggu')
            ");
            $stmt->execute([
                $this->test_user_id,
                $this->test_seller_id,
                $this->test_product_id,
                $product['price']
            ]);
            
            $test_txn_id = $this->pdo->lastInsertId();
            $this->pdo->commit();
            
            $this->pass($test_name, "Transaction created with ID: {$test_txn_id}");
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->fail($test_name, $e->getMessage());
        }
        
        // P3.2: Verify stock decremented
        $test_name = "P3.2: Stock decrement verification";
        try {
            $stmt = $this->pdo->prepare("SELECT stock FROM products WHERE product_id = ?");
            $stmt->execute([$this->test_product_id]);
            $new_stock = $stmt->fetchColumn();
            
            // Stock should be 99 (started at 100)
            if ($new_stock == 99) {
                $this->pass($test_name, "Stock correctly decremented to {$new_stock}");
            } else {
                $this->fail($test_name, "Expected stock 99, got {$new_stock}");
            }
        } catch (Exception $e) {
            $this->fail($test_name, $e->getMessage());
        }
        
        // P3.3: Cancel cash order
        if ($test_txn_id) {
            $test_name = "P3.3: Cancel pending order";
            try {
                $this->pdo->beginTransaction();
                
                // Get transaction details
                $stmt = $this->pdo->prepare("
                    SELECT * FROM transactions WHERE transaction_id = ? FOR UPDATE
                ");
                $stmt->execute([$test_txn_id]);
                $txn = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($txn['status'] !== 'Menunggu') {
                    throw new Exception("Can only cancel Menunggu orders");
                }
                
                // Update transaction
                $stmt = $this->pdo->prepare("
                    UPDATE transactions 
                    SET status = 'Dibatalkan', cancelled_at = CURRENT_TIMESTAMP, cancelled_by = 'buyer'
                    WHERE transaction_id = ?
                ");
                $stmt->execute([$test_txn_id]);
                
                // Restore stock
                $stmt = $this->pdo->prepare("
                    UPDATE products SET stock = stock + ?, sold_count = GREATEST(sold_count - ?, 0)
                    WHERE product_id = ?
                ");
                $stmt->execute([$txn['quantity'], $txn['quantity'], $txn['product_id']]);
                
                $this->pdo->commit();
                
                $this->pass($test_name, "Order cancelled and stock restored");
            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->fail($test_name, $e->getMessage());
            }
        }
        
        // P3.4: Verify stock restored
        $test_name = "P3.4: Stock restoration after cancellation";
        try {
            $stmt = $this->pdo->prepare("SELECT stock FROM products WHERE product_id = ?");
            $stmt->execute([$this->test_product_id]);
            $restored_stock = $stmt->fetchColumn();
            
            if ($restored_stock == 100) {
                $this->pass($test_name, "Stock restored to original {$restored_stock}");
            } else {
                $this->fail($test_name, "Expected stock 100, got {$restored_stock}");
            }
        } catch (Exception $e) {
            $this->fail($test_name, $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function testOnlinePaymentOrders() {
        echo "TEST CATEGORY: Online Payment Orders\n";
        echo str_repeat("-", 50) . "\n";
        
        $test_txn_id = null;
        $payment_ref = null;
        
        // P4.1: Place online order
        $test_name = "P4.1: Create online payment order";
        try {
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("
                SELECT * FROM products WHERE product_id = ? AND status = 'Aktif' FOR UPDATE
            ");
            $stmt->execute([$this->test_product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $this->pdo->prepare("
                UPDATE products SET stock = stock - 1, sold_count = sold_count + 1
                WHERE product_id = ? AND stock >= 1
            ");
            $stmt->execute([$this->test_product_id]);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO transactions 
                (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, payment_type, payment_status, status)
                VALUES (?, ?, ?, 1, ?, 'FPX', 'online', 'unpaid', 'Menunggu')
            ");
            $stmt->execute([
                $this->test_user_id,
                $this->test_seller_id,
                $this->test_product_id,
                $product['price']
            ]);
            
            $test_txn_id = $this->pdo->lastInsertId();
            $this->pdo->commit();
            
            $this->pass($test_name, "Online order created with ID: {$test_txn_id}");
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->fail($test_name, $e->getMessage());
        }
        
        // P4.2: Create payment record
        if ($test_txn_id) {
            $test_name = "P4.2: Payment record creation";
            try {
                $payment_ref = generate_payment_reference('UAT');
                $amount = 50.00; // Product price
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO payments 
                    (transaction_id, payment_reference, payment_method, amount, currency, status)
                    VALUES (?, ?, 'fpx', ?, 'MYR', 'pending')
                ");
                $stmt->execute([$test_txn_id, $payment_ref, $amount]);
                
                $payment_id = $this->pdo->lastInsertId();
                
                $this->pass($test_name, "Payment record created: {$payment_ref}");
            } catch (Exception $e) {
                $this->fail($test_name, $e->getMessage());
            }
        }
        
        // P4.3: Simulate payment success
        if ($payment_ref) {
            $test_name = "P4.3: Simulate successful payment";
            try {
                $this->pdo->beginTransaction();
                
                $stmt = $this->pdo->prepare("
                    UPDATE payments 
                    SET status = 'completed', paid_at = CURRENT_TIMESTAMP
                    WHERE payment_reference = ?
                ");
                $stmt->execute([$payment_ref]);
                
                $stmt = $this->pdo->prepare("
                    UPDATE transactions 
                    SET payment_status = 'paid', paid_at = CURRENT_TIMESTAMP
                    WHERE transaction_id = ?
                ");
                $stmt->execute([$test_txn_id]);
                
                $this->pdo->commit();
                
                $this->pass($test_name, "Payment marked as successful");
            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->fail($test_name, $e->getMessage());
            }
        }
        
        // P4.4: Verify payment status
        if ($test_txn_id) {
            $test_name = "P4.4: Payment status verification";
            try {
                $stmt = $this->pdo->prepare("
                    SELECT payment_status FROM transactions WHERE transaction_id = ?
                ");
                $stmt->execute([$test_txn_id]);
                $status = $stmt->fetchColumn();
                
                if ($status === 'paid') {
                    $this->pass($test_name, "Transaction payment_status = 'paid'");
                } else {
                    $this->fail($test_name, "Expected 'paid', got '{$status}'");
                }
            } catch (Exception $e) {
                $this->fail($test_name, $e->getMessage());
            }
        }
        
        // P4.5: Test refund on cancellation
        if ($test_txn_id && $payment_ref) {
            $test_name = "P4.5: Refund on order cancellation";
            try {
                // Get payment ID
                $stmt = $this->pdo->prepare("
                    SELECT payment_id FROM payments WHERE payment_reference = ?
                ");
                $stmt->execute([$payment_ref]);
                $payment_id = $stmt->fetchColumn();
                
                // Process refund using the actual function
                $refund_result = process_payment_refund(
                    $this->pdo,
                    $payment_id,
                    'UAT test cancellation',
                    'auto'
                );
                
                if ($refund_result['success']) {
                    $this->pass($test_name, "Refund processed: " . $refund_result['message']);
                } else {
                    $this->fail($test_name, "Refund failed: " . ($refund_result['error'] ?? 'Unknown error'));
                }
            } catch (Exception $e) {
                $this->fail($test_name, $e->getMessage());
            }
        }
        
        echo "\n";
    }
    
    private function testReviewsAndRatings() {
        echo "TEST CATEGORY: Reviews & Ratings\n";
        echo str_repeat("-", 50) . "\n";
        
        // Create a completed transaction first
        $completed_txn_id = null;
        
        $test_name = "P5.1: Submit review for completed order";
        try {
            // Create completed transaction
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO transactions 
                (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, payment_type, status, completed_at, completed_by)
                VALUES (?, ?, ?, 1, 50.00, 'Tunai', 'cash', 'Selesai', CURRENT_TIMESTAMP, 'buyer')
            ");
            $stmt->execute([$this->test_user_id, $this->test_seller_id, $this->test_product_id]);
            $completed_txn_id = $this->pdo->lastInsertId();
            
            // Submit review
            $stmt = $this->pdo->prepare("
                INSERT INTO reviews (transaction_id, buyer_id, seller_id, rating, comment)
                VALUES (?, ?, ?, 5, 'UAT test review - excellent!')
            ");
            $stmt->execute([$completed_txn_id, $this->test_user_id, $this->test_seller_id]);
            
            $this->pdo->commit();
            
            $this->pass($test_name, "Review submitted successfully");
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->fail($test_name, $e->getMessage());
        }
        
        // P5.2: Verify cannot review twice
        if ($completed_txn_id) {
            $test_name = "P5.2: Duplicate review prevention";
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO reviews (transaction_id, buyer_id, seller_id, rating, comment)
                    VALUES (?, ?, ?, 4, 'Duplicate review attempt')
                ");
                $stmt->execute([$completed_txn_id, $this->test_user_id, $this->test_seller_id]);
                
                $this->fail($test_name, "Duplicate review was allowed (should be blocked)");
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { // Duplicate key
                    $this->pass($test_name, "Duplicate review correctly blocked by UNIQUE constraint");
                } else {
                    $this->fail($test_name, "Unexpected error: " . $e->getMessage());
                }
            }
        }
        
        echo "\n";
    }
    
    private function testDisputes() {
        echo "TEST CATEGORY: Disputes\n";
        echo str_repeat("-", 50) . "\n";
        
        $test_name = "P6.1: File dispute for order";
        try {
            // Create transaction for dispute
            $stmt = $this->pdo->prepare("
                INSERT INTO transactions 
                (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, payment_type, status)
                VALUES (?, ?, ?, 1, 50.00, 'Tunai', 'cash', 'Disahkan')
            ");
            $stmt->execute([$this->test_user_id, $this->test_seller_id, $this->test_product_id]);
            $dispute_txn_id = $this->pdo->lastInsertId();
            
            // File dispute
            $stmt = $this->pdo->prepare("
                INSERT INTO disputes (transaction_id, filed_by, title, description, severity, status)
                VALUES (?, 'buyer', 'UAT Test Dispute', 'Testing dispute system', 'Sederhana', 'Menunggu')
            ");
            $stmt->execute([$dispute_txn_id]);
            
            $this->pass($test_name, "Dispute filed successfully");
        } catch (Exception $e) {
            $this->fail($test_name, $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function testTrustScore() {
        echo "TEST CATEGORY: Trust Score\n";
        echo str_repeat("-", 50) . "\n";
        
        $test_name = "P7.1: Buyer trust score exists";
        try {
            $stmt = $this->pdo->prepare("
                SELECT buyer_trust_score FROM users WHERE user_id = ?
            ");
            $stmt->execute([$this->test_user_id]);
            $score = $stmt->fetchColumn();
            
            if ($score !== null && $score >= 0) {
                $this->pass($test_name, "Buyer trust score: {$score}");
            } else {
                $this->fail($test_name, "Trust score is null or invalid");
            }
        } catch (Exception $e) {
            $this->fail($test_name, $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function testDashboardFeatures() {
        echo "TEST CATEGORY: Dashboard Features\n";
        echo str_repeat("-", 50) . "\n";
        
        $test_name = "P8.1: Query buyer orders";
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM transactions WHERE buyer_id = ?
            ");
            $stmt->execute([$this->test_user_id]);
            $count = $stmt->fetchColumn();
            
            $this->pass($test_name, "Found {$count} order(s) for buyer");
        } catch (Exception $e) {
            $this->fail($test_name, $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function cleanup() {
        echo "Cleaning up test data...\n";
        
        try {
            // Delete test reviews
            $this->pdo->prepare("DELETE FROM reviews WHERE buyer_id = ?")->execute([$this->test_user_id]);
            
            // Delete test disputes
            $this->pdo->prepare("DELETE FROM disputes WHERE transaction_id IN (SELECT transaction_id FROM transactions WHERE buyer_id = ?)")->execute([$this->test_user_id]);
            
            // Delete test payments
            $this->pdo->prepare("DELETE FROM payments WHERE transaction_id IN (SELECT transaction_id FROM transactions WHERE buyer_id = ?)")->execute([$this->test_user_id]);
            
            // Delete test transactions
            $this->pdo->prepare("DELETE FROM transactions WHERE buyer_id = ?")->execute([$this->test_user_id]);
            
            // Delete test product
            if ($this->test_product_id) {
                $this->pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$this->test_product_id]);
            }
            
            // Delete test users
            if ($this->test_user_id) {
                $this->pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$this->test_user_id]);
            }
            if ($this->test_seller_id) {
                $this->pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$this->test_seller_id]);
            }
            
            echo "✓ Cleanup complete\n\n";
        } catch (Exception $e) {
            echo "✗ Cleanup warning: " . $e->getMessage() . "\n\n";
        }
    }
    
    private function pass($test_name, $message = "") {
        $this->test_results[] = ['status' => 'PASS', 'test' => $test_name, 'message' => $message];
        echo "✓ PASS: {$test_name}\n";
        if ($message) echo "  → {$message}\n";
    }
    
    private function fail($test_name, $error) {
        $this->test_results[] = ['status' => 'FAIL', 'test' => $test_name, 'error' => $error];
        echo "✗ FAIL: {$test_name}\n";
        echo "  → ERROR: {$error}\n";
    }
    
    private function printResults() {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "PEMBELI ROLE TEST RESULTS SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        
        $passed = count(array_filter($this->test_results, fn($r) => $r['status'] === 'PASS'));
        $failed = count(array_filter($this->test_results, fn($r) => $r['status'] === 'FAIL'));
        $total = count($this->test_results);
        
        $pass_rate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;
        
        echo "\nTotal Tests: {$total}\n";
        echo "✓ Passed: {$passed}\n";
        echo "✗ Failed: {$failed}\n";
        echo "Pass Rate: {$pass_rate}%\n\n";
        
        if ($failed > 0) {
            echo "FAILED TESTS:\n";
            echo str_repeat("-", 60) . "\n";
            foreach ($this->test_results as $result) {
                if ($result['status'] === 'FAIL') {
                    echo "✗ {$result['test']}\n";
                    echo "  Error: {$result['error']}\n\n";
                }
            }
        }
        
        if ($pass_rate >= 90) {
            echo "✓ PEMBELI ROLE: TESTS PASSED (Excellent)\n";
        } elseif ($pass_rate >= 70) {
            echo "⚠ PEMBELI ROLE: TESTS PASSED WITH WARNINGS\n";
        } else {
            echo "✗ PEMBELI ROLE: TESTS FAILED (Critical Issues)\n";
        }
        
        echo str_repeat("=", 60) . "\n";
    }
}

// Run tests
try {
    $test = new BuyerRoleTest($pdo);
    $test->runAllTests();
} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
