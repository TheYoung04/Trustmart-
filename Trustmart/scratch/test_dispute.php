<?php
// scratch/test_dispute.php
// Automated integration test for buyer dispute submission flow using cURL.

require_once __DIR__ . '/../config/db.php';

function test_url($url, $method = 'GET', $data = [], $cookie = '') {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    if ($cookie) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    curl_setopt($ch, CURLOPT_HEADER, 1);
    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Parse cookies
    $new_cookie = $cookie;
    if (preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches)) {
        foreach($matches[1] as $c) {
            $new_cookie = $c;
        }
    }
    return ['status' => $status, 'body' => $body, 'cookie' => $new_cookie, 'header' => $header];
}

$base = 'http://localhost/Trustmart';

echo "=== STARTING DISPUTE FLOW INTEGRATION TEST ===\n";

$txn_id = null;
try {
    // 1. Log in as Buyer (Aishah) to get the session cookie
    echo "1. Logging in as buyer...\n";
    $res = test_url("$base/auth/login.php", 'POST', [
        'email' => 'aishah@student.uthm.edu.my',
        'password' => 'password123'
    ]);
    if ($res['status'] !== 200) {
        throw new Exception("Login failed. Status: " . $res['status'] . " Body: " . $res['body']);
    }
    $cookie = $res['cookie'];
    echo "Login successful. Cookie acquired.\n";

    // 2. Create a mock transaction in the database (status = 'Disahkan') so we have a valid target
    echo "2. Creating a mock transaction in DB...\n";
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = 'aishah@student.uthm.edu.my'");
    $stmt->execute();
    $buyer_id = $stmt->fetchColumn();
    if (!$buyer_id) {
        throw new Exception("Buyer 'Aishah' not found in database.");
    }

    $stmt = $pdo->prepare("
        INSERT INTO transactions (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, status)
        VALUES (?, 2, 1, 1, 1500.00, 'Tunai', 'Disahkan')
    ");
    $stmt->execute([$buyer_id]);
    $txn_id = $pdo->lastInsertId();
    echo "Mock transaction created with ID: $txn_id\n";

    // 3. Send AJAX submit_dispute request
    echo "3. Sending submit_dispute request via cURL...\n";
    $res = test_url("$base/dashboard/buyer.php?action=submit_dispute", 'POST', [
        'transaction_id' => $txn_id,
        'title' => 'Barang Rosak Semasa Dihantar',
        'description' => 'Saya menerima laptop dalam keadaan skrin retak teruk. Sila bantu saya.',
        'severity' => 'Mendesak'
    ], $cookie);

    echo "Response Status: " . $res['status'] . "\n";
    echo "Response Body: " . $res['body'] . "\n";

    $response_data = json_decode($res['body'], true);
    if (isset($response_data['success']) && $response_data['success'] === true) {
        echo "✅ SUCCESS: Dispute submitted successfully!\n";
    } else {
        echo "❌ FAILED: Dispute submission failed!\n";
    }

    // 4. Verify the dispute is inserted in DB
    $stmt = $pdo->prepare("SELECT dispute_id, status, severity, title FROM disputes WHERE transaction_id = ?");
    $stmt->execute([$txn_id]);
    $dispute = $stmt->fetch();
    if ($dispute) {
        echo "Dispute found in DB! ID: " . $dispute['dispute_id'] . " | Status: " . $dispute['status'] . " | Severity: " . $dispute['severity'] . "\n";
    } else {
        echo "❌ ERROR: Dispute not found in database.\n";
    }

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
} finally {
    // 5. Clean up DB records
    if ($txn_id) {
        echo "\n=== CLEANING UP DATABASE ===\n";
        $pdo->prepare("DELETE FROM disputes WHERE transaction_id = ?")->execute([$txn_id]);
        echo "Cleared dispute for txn $txn_id\n";
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([$txn_id]);
        echo "Cleared transaction $txn_id\n";
    }
}
