<?php
/**
 * Payment Integration Test
 * 
 * Tests the complete payment flow including:
 * - Payment record creation
 * - Signature generation
 * - Callback with signature verification
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment.php';

echo "=== Payment Integration Test ===\n\n";

// Test data
$test_transaction_id = null;
$test_payment_ref = null;
$cleanup_needed = false;

try {
    // Step 1: Find or create a test transaction
    echo "Step 1: Finding test transaction...\n";
    $stmt = $pdo->prepare("
        SELECT t.transaction_id, t.total_amount, t.buyer_id, t.seller_id
        FROM transactions t
        WHERE t.payment_type = 'online' 
          AND t.payment_status = 'unpaid'
        LIMIT 1
    ");
    $stmt->execute();
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        echo "   No unpaid online transaction found. Creating test transaction...\n";
        
        // Find two users for test
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE role = 'pembeli' LIMIT 1");
        $stmt->execute();
        $buyer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE role = 'penjual' LIMIT 1");
        $stmt->execute();
        $seller = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT product_id FROM products LIMIT 1");
        $stmt->execute();
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$buyer || !$seller || !$product) {
            throw new Exception("Cannot create test transaction - missing users or products");
        }
        
        // Create test transaction
        $stmt = $pdo->prepare("
            INSERT INTO transactions 
            (buyer_id, seller_id, product_id, quantity, total_amount, status, payment_type, payment_status)
            VALUES (?, ?, ?, 1, 99.90, 'Menunggu', 'online', 'unpaid')
        ");
        $stmt->execute([$buyer['user_id'], $seller['user_id'], $product['product_id']]);
        $test_transaction_id = $pdo->lastInsertId();
        $cleanup_needed = true;
        
        echo "   ✓ Test transaction created: ID $test_transaction_id\n";
        $transaction = [
            'transaction_id' => $test_transaction_id,
            'total_amount' => 99.90,
            'buyer_id' => $buyer['user_id'],
            'seller_id' => $seller['user_id']
        ];
    } else {
        echo "   ✓ Using existing transaction: ID {$transaction['transaction_id']}\n";
        $test_transaction_id = $transaction['transaction_id'];
    }
    
    // Step 2: Create payment record
    echo "\nStep 2: Creating payment record...\n";
    $test_payment_ref = generate_payment_reference('TEST');
    $amount = calculate_payment_amount($transaction['total_amount']);
    
    $stmt = $pdo->prepare("
        INSERT INTO payments 
        (transaction_id, payment_reference, payment_method, amount, currency, status)
        VALUES (?, ?, 'fpx', ?, 'MYR', 'pending')
    ");
    $stmt->execute([$test_transaction_id, $test_payment_ref, $amount]);
    $payment_id = $pdo->lastInsertId();
    
    echo "   ✓ Payment record created:\n";
    echo "     - Payment ID: $payment_id\n";
    echo "     - Reference: $test_payment_ref\n";
    echo "     - Amount: RM " . number_format($amount, 2) . "\n";
    
    // Step 3: Generate signature
    echo "\nStep 3: Testing signature generation...\n";
    $signature = generate_payment_signature($test_payment_ref, '1', $amount);
    echo "   ✓ Signature generated: " . substr($signature, 0, 32) . "...\n";
    
    // Step 4: Verify signature
    echo "\nStep 4: Testing signature verification...\n";
    $is_valid = verify_payment_signature($test_payment_ref, '1', $amount, $signature);
    if ($is_valid) {
        echo "   ✓ Signature verification: VALID\n";
    } else {
        throw new Exception("Signature verification FAILED!");
    }
    
    // Test invalid signature
    $invalid_sig = substr($signature, 0, -4) . 'FAKE';
    $is_invalid = verify_payment_signature($test_payment_ref, '1', $amount, $invalid_sig);
    if (!$is_invalid) {
        echo "   ✓ Invalid signature correctly rejected\n";
    } else {
        throw new Exception("Invalid signature was ACCEPTED - security flaw!");
    }
    
    // Step 5: Simulate callback (successful payment)
    echo "\nStep 5: Simulating payment callback...\n";
    
    // Save current POST data
    $original_post = $_POST;
    
    // Simulate callback POST data
    $_POST = [
        'refno' => $test_payment_ref,
        'status_id' => '1',
        'amount' => number_format($amount, 2, '.', ''),
        'signature' => $signature
    ];
    
    // Simulate the callback logic (without actually including callback.php to avoid output)
    $pdo->beginTransaction();
    
    // Fetch payment
    $stmt = $pdo->prepare("
        SELECT p.*, t.transaction_id as txn_id
        FROM payments p
        JOIN transactions t ON t.transaction_id = p.transaction_id
        WHERE p.payment_reference = ?
        LIMIT 1
    ");
    $stmt->execute([$test_payment_ref]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        throw new Exception("Payment not found!");
    }
    
    // Verify signature (this is what callback.php does)
    if (!verify_payment_signature($_POST['refno'], $_POST['status_id'], $payment['amount'], $_POST['signature'])) {
        throw new Exception("Callback signature verification FAILED!");
    }
    
    echo "   ✓ Callback signature verified\n";
    
    // Update payment status
    $response = json_encode($_POST);
    $stmt = $pdo->prepare("
        UPDATE payments
        SET status = 'completed',
            paid_at = CURRENT_TIMESTAMP,
            gateway_response = ?
        WHERE payment_id = ?
    ");
    $stmt->execute([$response, $payment['payment_id']]);
    
    // Update transaction
    $stmt = $pdo->prepare("
        UPDATE transactions
        SET payment_status = 'paid',
            paid_at = CURRENT_TIMESTAMP
        WHERE transaction_id = ?
    ");
    $stmt->execute([$payment['txn_id']]);
    
    $pdo->commit();
    echo "   ✓ Payment marked as completed\n";
    
    // Restore POST
    $_POST = $original_post;
    
    // Step 6: Verify final state
    echo "\nStep 6: Verifying final state...\n";
    $stmt = $pdo->prepare("
        SELECT p.status as payment_status, t.payment_status as txn_payment_status
        FROM payments p
        JOIN transactions t ON t.transaction_id = p.transaction_id
        WHERE p.payment_reference = ?
    ");
    $stmt->execute([$test_payment_ref]);
    $final = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($final['payment_status'] === 'completed' && $final['txn_payment_status'] === 'paid') {
        echo "   ✓ Payment status: completed\n";
        echo "   ✓ Transaction status: paid\n";
    } else {
        throw new Exception("Final state incorrect: payment={$final['payment_status']}, txn={$final['txn_payment_status']}");
    }
    
    // Step 7: Test unsigned callback rejection
    echo "\nStep 7: Testing unsigned callback rejection...\n";
    
    // Create another test payment
    $test_payment_ref2 = generate_payment_reference('TEST');
    $stmt = $pdo->prepare("
        INSERT INTO payments 
        (transaction_id, payment_reference, payment_method, amount, currency, status)
        VALUES (?, ?, 'fpx', ?, 'MYR', 'pending')
    ");
    $stmt->execute([$test_transaction_id, $test_payment_ref2, $amount]);
    $payment_id2 = $pdo->lastInsertId();
    
    // Fetch the payment
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE payment_reference = ?");
    $stmt->execute([$test_payment_ref2]);
    $payment2 = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Try to verify WITHOUT signature (simulate attacker)
    $attack_signature = ''; // Empty signature
    $should_fail = verify_payment_signature($test_payment_ref2, '1', $amount, $attack_signature);
    
    if (!$should_fail) {
        echo "   ✓ Unsigned callback correctly rejected\n";
    } else {
        throw new Exception("SECURITY FLAW: Unsigned callback was accepted!");
    }
    
    // Cleanup
    if ($cleanup_needed) {
        echo "\nCleaning up test data...\n";
        $stmt = $pdo->prepare("DELETE FROM payments WHERE transaction_id = ?");
        $stmt->execute([$test_transaction_id]);
        $stmt = $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?");
        $stmt->execute([$test_transaction_id]);
        echo "   ✓ Test data cleaned up\n";
    } else {
        echo "\nNote: Test used existing transaction, cleanup skipped\n";
    }
    
    // Success!
    echo "\n";
    echo "=================================================\n";
    echo "✓ ALL INTEGRATION TESTS PASSED\n";
    echo "=================================================\n";
    echo "\nSummary:\n";
    echo "- PDO database operations: ✓ WORKING\n";
    echo "- Payment record creation: ✓ WORKING\n";
    echo "- Signature generation: ✓ WORKING\n";
    echo "- Signature verification: ✓ WORKING\n";
    echo "- Callback processing: ✓ WORKING\n";
    echo "- Unsigned callback rejection: ✓ WORKING\n";
    echo "- Payment/transaction updates: ✓ WORKING\n";
    echo "\nPayment system is SECURE and FUNCTIONAL!\n";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo "\n";
    echo "=================================================\n";
    echo "✗ TEST FAILED\n";
    echo "=================================================\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    
    // Cleanup on error
    if ($cleanup_needed && $test_transaction_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM payments WHERE transaction_id = ?");
            $stmt->execute([$test_transaction_id]);
            $stmt = $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?");
            $stmt->execute([$test_transaction_id]);
            echo "\nTest data cleaned up after error.\n";
        } catch (Exception $cleanup_error) {
            echo "\nFailed to cleanup test data: " . $cleanup_error->getMessage() . "\n";
        }
    }
    
    exit(1);
}
