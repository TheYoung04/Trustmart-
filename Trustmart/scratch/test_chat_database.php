<?php
require_once __DIR__ . '/../config/db.php';

echo "RUNNING CHAT DATABASE TESTS...\n";

// Clear previous test messages
$pdo->exec("DELETE FROM messages WHERE message_text LIKE '[TEST]%'");

try {
    // 1. Find a buyer and a seller to test
    $buyer = $pdo->query("SELECT user_id, full_name FROM users WHERE role='pembeli' LIMIT 1")->fetch();
    $seller = $pdo->query("SELECT user_id, full_name FROM users WHERE role='penjual' LIMIT 1")->fetch();
    $product = $pdo->query("SELECT product_id, name FROM products LIMIT 1")->fetch();
    
    if (!$buyer || !$seller) {
        die("ERROR: Test requires at least one buyer and one seller in 'users' table.\n");
    }
    
    echo "Buyer: {$buyer['full_name']} (ID: {$buyer['user_id']})\n";
    echo "Seller: {$seller['full_name']} (ID: {$seller['user_id']})\n";
    if ($product) {
        echo "Product: {$product['name']} (ID: {$product['product_id']})\n";
    }
    
    // 2. Insert test message
    $ins = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, product_id, message_text) VALUES (?, ?, ?, ?)");
    $ins->execute([
        $buyer['user_id'],
        $seller['user_id'],
        $product ? $product['product_id'] : null,
        "[TEST] Hello, I am interested in this product!"
    ]);
    $msg_id = $pdo->lastInsertId();
    echo "SUCCESS: Message inserted with ID: $msg_id\n";
    
    // 3. Verify message fields
    $sel = $pdo->prepare("SELECT * FROM messages WHERE message_id = ?");
    $sel->execute([$msg_id]);
    $msg = $sel->fetch();
    
    if ($msg && $msg['message_text'] === "[TEST] Hello, I am interested in this product!") {
        echo "SUCCESS: Message text and receiver match.\n";
    } else {
        echo "FAILED: Message text or receiver mismatch.\n";
    }
    
    // 4. Test fetch contacts query logic
    $contacts_sql = "
        SELECT DISTINCT u.user_id, u.full_name
          FROM users u
         WHERE u.user_id IN (
             SELECT sender_id FROM messages WHERE receiver_id = ?
             UNION
             SELECT receiver_id FROM messages WHERE sender_id = ?
         )
    ";
    $c_stmt = $pdo->prepare($contacts_sql);
    $c_stmt->execute([$seller['user_id'], $seller['user_id']]);
    $contacts = $c_stmt->fetchAll();
    
    echo "Contacts found for Seller: " . count($contacts) . "\n";
    foreach ($contacts as $c) {
        echo " - Contact: {$c['full_name']} (ID: {$c['user_id']})\n";
    }
    
    // Clean up
    $pdo->exec("DELETE FROM messages WHERE message_id = $msg_id");
    echo "SUCCESS: Cleaned up test message.\n";
    
} catch (PDOException $e) {
    echo "DATABASE ERROR: " . $e->getMessage() . "\n";
}
