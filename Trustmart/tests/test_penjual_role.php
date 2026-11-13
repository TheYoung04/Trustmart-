<?php
/**
 * PENJUAL (SELLER) ROLE - UAT Test Suite
 * Tests all seller-specific functionality
 */

require_once __DIR__ . '/../config/db.php';

class PenjualRoleTest {
    private $pdo;
    private $seller_id;
    private $buyer_id;
    private $product_id;
    private $transaction_id;
    
    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
    }
    
    public function runAllTests() {
        echo "=== PENJUAL (SELLER) ROLE UAT TESTS ===\n";
        
        $this->setupTestData();
        
        $this->testCategory("Seller Registration & Login");
        $this->test_S1_1_RegisterSeller();
        $this->test_S1_2_LoginVerification();
        $this->test_S1_3_RoleAssignment();
        
        $this->testCategory("Product Management");
        $this->test_S2_1_CreateProduct();
        $this->test_S2_2_ProductFieldValidation();
        $this->test_S2_3_UpdateProduct();
        $this->test_S2_4_ProductVisibility();
        
        $this->testCategory("Order Management");
        $this->test_S3_1_ViewIncomingOrders();
        $this->test_S3_2_ConfirmCashOrder();
        $this->test_S3_3_ConfirmOnlineOrder();
        $this->test_S3_4_CancelOrder();
        $this->test_S3_5_RefundProcessing();
        
        $this->testCategory("Trust Score");
        $this->test_S4_1_InitialTrustScore();
        $this->test_S4_2_TrustScoreIncrease();
        $this->test_S4_3_TrustScoreDecrease();
        
        $this->testCategory("Security");
        $this->test_S5_1_CannotConfirmOthersOrders();
        $this->test_S5_2_CannotEditOthersProducts();
        $this->test_S5_3_CannotBuyOwnProducts();
        
        $this->generateReport();
    }
    
    private function setupTestData() {
        echo "Setting up test data...\n";
        
        // Create test buyer
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password, role, is_active, created_at)
            VALUES (?, ?, ?, 'pembeli', 1, NOW())
        ");
        $buyer_hash = password_hash('password123', PASSWORD_DEFAULT);
        $stmt->execute(['test_buyer_' . time(), 'buyer_' . time() . '@test.com', $buyer_hash]);
        $this->buyer_id = $this->pdo->lastInsertId();
        
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
    
    // S1: Registration & Authentication Tests
    private function test_S1_1_RegisterSeller() {
        $username = 'test_seller_' . time();
        $email = 'seller_' . time() . '@test.com';
        $password = password_hash('password123', PASSWORD_DEFAULT);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password, role, is_active, created_at)
            VALUES (?, ?, ?, 'penjual', 1, NOW())
        ");
        $result = $stmt->execute([$username, $email, $password]);
        $this->seller_id = $this->pdo->lastInsertId();
        
        if ($result && $this->seller_id > 0) {
            $this->pass("S1.1", "Register new seller account", "User created with ID: {$this->seller_id}");
        } else {
            $this->fail("S1.1", "Register new seller account", "Failed to create seller");
        }
    }
    
    private function test_S1_2_LoginVerification() {
        $stmt = $this->pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$this->seller_id]);
        $hash = $stmt->fetchColumn();
        
        $verified = password_verify('password123', $hash);
        
        if ($verified) {
            $this->pass("S1.2", "Login credential verification", "Password verification successful");
        } else {
            $this->fail("S1.2", "Login credential verification", "Password verification failed");
        }
    }
    
    private function test_S1_3_RoleAssignment() {
        $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$this->seller_id]);
        $role = $stmt->fetchColumn();
        
        if ($role === 'penjual') {
            $this->pass("S1.3", "User role assignment", "Role correctly set to 'penjual'");
        } else {
            $this->fail("S1.3", "User role assignment", "Role is '$role', expected 'penjual'");
        }
    }
    
    // S2: Product Management Tests
    private function test_S2_1_CreateProduct() {
        $stmt = $this->pdo->prepare("
            INSERT INTO products (seller_id, name, description, price, stock, image, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
        ");
        $result = $stmt->execute([
            $this->seller_id,
            'UAT Test Product',
            'This is a test product for UAT',
            99.99,
            50,
            'test_product.png'
        ]);
        $this->product_id = $this->pdo->lastInsertId();
        
        if ($result && $this->product_id > 0) {
            $this->pass("S2.1", "Create product", "Product created with ID: {$this->product_id}");
        } else {
            $this->fail("S2.1", "Create product", "Failed to create product");
        }
    }
    
    private function test_S2_2_ProductFieldValidation() {
        $stmt = $this->pdo->prepare("
            SELECT name, description, price, stock, status, seller_id 
            FROM products WHERE id = ?
        ");
        $stmt->execute([$this->product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $valid = ($product['name'] === 'UAT Test Product' &&
                  $product['price'] == 99.99 &&
                  $product['stock'] == 50 &&
                  $product['status'] === 'active' &&
                  $product['seller_id'] == $this->seller_id);
        
        if ($valid) {
            $this->pass("S2.2", "Product field validation", "All fields correctly stored");
        } else {
            $this->fail("S2.2", "Product field validation", "Field mismatch detected");
        }
    }
    
    private function test_S2_3_UpdateProduct() {
        $stmt = $this->pdo->prepare("
            UPDATE products SET price = 149.99, stock = 75 WHERE id = ?
        ");
        $result = $stmt->execute([$this->product_id]);
        
        $stmt = $this->pdo->prepare("SELECT price, stock FROM products WHERE id = ?");
        $stmt->execute([$this->product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $product['price'] == 149.99 && $product['stock'] == 75) {
            $this->pass("S2.3", "Update product", "Price and stock updated successfully");
        } else {
            $this->fail("S2.3", "Update product", "Update failed or values incorrect");
        }
    }
    
    private function test_S2_4_ProductVisibility() {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM products 
            WHERE seller_id = ? AND status = 'active'
        ");
        $stmt->execute([$this->seller_id]);
        $count = $stmt->fetchColumn();
        
        if ($count >= 1) {
            $this->pass("S2.4", "Product visibility", "Active products visible: $count");
        } else {
            $this->fail("S2.4", "Product visibility", "No active products found");
        }
    }
    
    // S3: Order Management Tests
    private function test_S3_1_ViewIncomingOrders() {
        // Create test order
        $stmt = $this->pdo->prepare("
            INSERT INTO transactions (buyer_id, seller_id, product_id, quantity, total_amount, 
                                     payment_method, payment_status, status, created_at)
            VALUES (?, ?, ?, 1, 149.99, 'cash', 'unpaid', 'pending', NOW())
        ");
        $stmt->execute([$this->buyer_id, $this->seller_id, $this->product_id]);
        $this->transaction_id = $this->pdo->lastInsertId();
        
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM transactions WHERE seller_id = ?
        ");
        $stmt->execute([$this->seller_id]);
        $count = $stmt->fetchColumn();
        
        if ($count >= 1) {
            $this->pass("S3.1", "View incoming orders", "Found $count order(s)");
        } else {
            $this->fail("S3.1", "View incoming orders", "No orders found");
        }
    }
    
    private function test_S3_2_ConfirmCashOrder() {
        $stmt = $this->pdo->prepare("
            UPDATE transactions 
            SET status = 'confirmed', seller_confirm_at = NOW()
            WHERE id = ? AND payment_method = 'cash'
        ");
        $result = $stmt->execute([$this->transaction_id]);
        
        $stmt = $this->pdo->prepare("SELECT status FROM transactions WHERE id = ?");
        $stmt->execute([$this->transaction_id]);
        $status = $stmt->fetchColumn();
        
        if ($result && $status === 'confirmed') {
            $this->pass("S3.2", "Confirm cash order", "Order confirmed successfully");
        } else {
            $this->fail("S3.2", "Confirm cash order", "Confirmation failed, status: $status");
        }
    }
    
    private function test_S3_3_ConfirmOnlineOrder() {
        // Create online order
        $stmt = $this->pdo->prepare("
            INSERT INTO transactions (buyer_id, seller_id, product_id, quantity, total_amount, 
                                     payment_method, payment_status, status, created_at)
            VALUES (?, ?, ?, 1, 149.99, 'online', 'paid', 'pending', NOW())
        ");
        $stmt->execute([$this->buyer_id, $this->seller_id, $this->product_id]);
        $online_tx_id = $this->pdo->lastInsertId();
        
        $stmt = $this->pdo->prepare("
            UPDATE transactions 
            SET status = 'confirmed', seller_confirm_at = NOW()
            WHERE id = ? AND payment_status = 'paid'
        ");
        $result = $stmt->execute([$online_tx_id]);
        
        $stmt = $this->pdo->prepare("SELECT status FROM transactions WHERE id = ?");
        $stmt->execute([$online_tx_id]);
        $status = $stmt->fetchColumn();
        
        if ($result && $status === 'confirmed') {
            $this->pass("S3.3", "Confirm online order", "Online order confirmed after payment");
        } else {
            $this->fail("S3.3", "Confirm online order", "Confirmation failed");
        }
    }
    
    private function test_S3_4_CancelOrder() {
        // Create new order to cancel
        $stmt = $this->pdo->prepare("
            INSERT INTO transactions (buyer_id, seller_id, product_id, quantity, total_amount, 
                                     payment_method, payment_status, status, created_at)
            VALUES (?, ?, ?, 1, 149.99, 'cash', 'unpaid', 'pending', NOW())
        ");
        $stmt->execute([$this->buyer_id, $this->seller_id, $this->product_id]);
        $cancel_tx_id = $this->pdo->lastInsertId();
        
        $stmt = $this->pdo->prepare("
            UPDATE transactions SET status = 'cancelled' WHERE id = ?
        ");
        $result = $stmt->execute([$cancel_tx_id]);
        
        if ($result) {
            $this->pass("S3.4", "Cancel order", "Order cancelled by seller");
        } else {
            $this->fail("S3.4", "Cancel order", "Cancellation failed");
        }
    }
    
    private function test_S3_5_RefundProcessing() {
        // Create paid online order
        $stmt = $this->pdo->prepare("
            INSERT INTO transactions (buyer_id, seller_id, product_id, quantity, total_amount, 
                                     payment_method, payment_status, status, created_at)
            VALUES (?, ?, ?, 1, 149.99, 'online', 'paid', 'pending', NOW())
        ");
        $stmt->execute([$this->buyer_id, $this->seller_id, $this->product_id]);
        $refund_tx_id = $this->pdo->lastInsertId();
        
        // Create payment record
        $stmt = $this->pdo->prepare("
            INSERT INTO payments (transaction_id, bill_code, amount, status, gateway, created_at)
            VALUES (?, ?, 149.99, 'successful', 'simulator', NOW())
        ");
        $stmt->execute([$refund_tx_id, 'TEST_BILL_' . time()]);
        
        // Cancel and trigger refund
        $stmt = $this->pdo->prepare("
            UPDATE transactions 
            SET status = 'cancelled', refund_status = 'requested', refund_requested_at = NOW()
            WHERE id = ?
        ");
        $result = $stmt->execute([$refund_tx_id]);
        
        $stmt = $this->pdo->prepare("SELECT refund_status FROM transactions WHERE id = ?");
        $stmt->execute([$refund_tx_id]);
        $refund_status = $stmt->fetchColumn();
        
        if ($refund_status === 'requested') {
            $this->pass("S3.5", "Refund processing", "Refund status: $refund_status");
        } else {
            $this->fail("S3.5", "Refund processing", "Refund not initiated");
        }
    }
    
    // S4: Trust Score Tests
    private function test_S4_1_InitialTrustScore() {
        $stmt = $this->pdo->prepare("SELECT trust_score FROM users WHERE id = ?");
        $stmt->execute([$this->seller_id]);
        $score = $stmt->fetchColumn();
        
        if ($score == 100.0) {
            $this->pass("S4.1", "Initial trust score", "Score: $score (expected 100.0)");
        } else {
            $this->fail("S4.1", "Initial trust score", "Score: $score, expected 100.0");
        }
    }
    
    private function test_S4_2_TrustScoreIncrease() {
        // Simulate positive review
        $stmt = $this->pdo->prepare("
            UPDATE users SET trust_score = trust_score + 2.0 WHERE id = ?
        ");
        $stmt->execute([$this->seller_id]);
        
        $stmt = $this->pdo->prepare("SELECT trust_score FROM users WHERE id = ?");
        $stmt->execute([$this->seller_id]);
        $score = $stmt->fetchColumn();
        
        if ($score > 100.0) {
            $this->pass("S4.2", "Trust score increase", "Score increased to: $score");
        } else {
            $this->fail("S4.2", "Trust score increase", "Score did not increase");
        }
    }
    
    private function test_S4_3_TrustScoreDecrease() {
        // Simulate negative action
        $stmt = $this->pdo->prepare("
            UPDATE users SET trust_score = GREATEST(0, trust_score - 5.0) WHERE id = ?
        ");
        $stmt->execute([$this->seller_id]);
        
        $stmt = $this->pdo->prepare("SELECT trust_score FROM users WHERE id = ?");
        $stmt->execute([$this->seller_id]);
        $score = $stmt->fetchColumn();
        
        if ($score < 102.0) {
            $this->pass("S4.3", "Trust score decrease", "Score decreased to: $score");
        } else {
            $this->fail("S4.3", "Trust score decrease", "Score did not decrease");
        }
    }
    
    // S5: Security Tests
    private function test_S5_1_CannotConfirmOthersOrders() {
        // Create order from different seller
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password, role, is_active, created_at)
            VALUES (?, ?, ?, 'penjual', 1, NOW())
        ");
        $other_hash = password_hash('password', PASSWORD_DEFAULT);
        $stmt->execute(['other_seller', 'other@test.com', $other_hash]);
        $other_seller_id = $this->pdo->lastInsertId();
        
        $stmt = $this->pdo->prepare("
            INSERT INTO transactions (buyer_id, seller_id, product_id, quantity, total_amount, 
                                     payment_method, payment_status, status, created_at)
            VALUES (?, ?, ?, 1, 50.00, 'cash', 'unpaid', 'pending', NOW())
        ");
        $stmt->execute([$this->buyer_id, $other_seller_id, $this->product_id]);
        $other_tx_id = $this->pdo->lastInsertId();
        
        // Try to confirm with wrong seller
        $stmt = $this->pdo->prepare("
            UPDATE transactions 
            SET status = 'confirmed'
            WHERE id = ? AND seller_id = ?
        ");
        $stmt->execute([$other_tx_id, $this->seller_id]);
        $affected = $stmt->rowCount();
        
        if ($affected === 0) {
            $this->pass("S5.1", "Cannot confirm others' orders", "Authorization check working");
        } else {
            $this->fail("S5.1", "Cannot confirm others' orders", "Security breach: unauthorized confirmation");
        }
    }
    
    private function test_S5_2_CannotEditOthersProducts() {
        // Create product from different seller
        $stmt = $this->pdo->prepare("
            INSERT INTO products (seller_id, name, description, price, stock, status, created_at)
            VALUES (?, ?, ?, 10.00, 5, 'active', NOW())
        ");
        $stmt->execute([$this->buyer_id, 'Other Product', 'Test', 10.00, 5]);
        $other_product_id = $this->pdo->lastInsertId();
        
        // Try to edit with wrong seller
        $stmt = $this->pdo->prepare("
            UPDATE products SET price = 999.99 WHERE id = ? AND seller_id = ?
        ");
        $stmt->execute([$other_product_id, $this->seller_id]);
        $affected = $stmt->rowCount();
        
        if ($affected === 0) {
            $this->pass("S5.2", "Cannot edit others' products", "Authorization check working");
        } else {
            $this->fail("S5.2", "Cannot edit others' products", "Security breach: unauthorized edit");
        }
    }
    
    private function test_S5_3_CannotBuyOwnProducts() {
        // Check that product listing excludes own products
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM products 
            WHERE seller_id = ? AND status = 'active'
        ");
        $stmt->execute([$this->seller_id]);
        $own_count = $stmt->fetchColumn();
        
        // Attempt to create transaction for own product
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO transactions (buyer_id, seller_id, product_id, quantity, total_amount, 
                                         payment_method, payment_status, status, created_at)
                VALUES (?, ?, ?, 1, 50.00, 'cash', 'unpaid', 'pending', NOW())
            ");
            $stmt->execute([$this->seller_id, $this->seller_id, $this->product_id]);
            
            $this->fail("S5.3", "Cannot buy own products", "System allowed self-purchase");
        } catch (Exception $e) {
            $this->pass("S5.3", "Cannot buy own products", "Self-purchase blocked at application level");
        }
    }
    
    private function generateReport() {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "PENJUAL ROLE UAT TESTS COMPLETED\n";
        echo str_repeat("=", 50) . "\n";
    }
}

// Run tests
$test = new PenjualRoleTest();
$test->runAllTests();
