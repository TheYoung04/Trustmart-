<?php
// Test script for core flows
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
            $new_cookie = $c; // Simplistic
        }
    }
    return ['status' => $status, 'body' => $body, 'cookie' => $new_cookie, 'header' => $header];
}

$base = 'http://localhost/Trustmart';

echo "1. Testing Login as Admin...\n";
$res = test_url("$base/auth/login.php", 'POST', ['email' => 'admin@uthm.edu.my', 'password' => 'password123']);
echo "Login Status: " . $res['status'] . "\n";
$cookie = $res['cookie'];

$res = test_url("$base/dashboard/admin.php", 'GET', [], $cookie);
echo "Admin Dashboard Status: " . $res['status'] . " (Expected 200)\n";
if (strpos($res['body'], 'Pentadbir PTU') !== false) {
    echo "Admin UI loaded successfully.\n";
} else {
    echo "Admin UI failed to load.\n";
}

echo "\n2. Testing Logout...\n";
$res = test_url("$base/auth/logout.php", 'GET', [], $cookie);
echo "Logout Status: " . $res['status'] . "\n";

echo "\n3. Testing Login as Buyer...\n";
$res = test_url("$base/auth/login.php", 'POST', ['email' => 'aishah@student.uthm.edu.my', 'password' => 'password123']);
echo "Login Status: " . $res['status'] . "\n";
$cookie = $res['cookie'];

$res = test_url("$base/dashboard/buyer.php", 'GET', [], $cookie);
echo "Buyer Dashboard Status: " . $res['status'] . " (Expected 200)\n";
if (strpos($res['body'], 'Pembeli') !== false) {
    echo "Buyer UI loaded successfully.\n";
} else {
    echo "Buyer UI failed to load.\n";
}

echo "\n4. Testing Product Purchase API as Buyer...\n";
// Product 1 exists and is active.
$res = test_url("$base/product.php?action=make_order&id=3", 'POST', ['quantity' => 1, 'payment_method' => 'Tunai'], $cookie);
echo "Purchase Status: " . $res['status'] . "\n";
echo "Purchase Body: " . $res['body'] . "\n";

echo "\n5. Testing Login as Seller...\n";
$res = test_url("$base/auth/login.php", 'POST', ['email' => 'haziq@student.uthm.edu.my', 'password' => 'password123']);
$cookie = $res['cookie'];

$res = test_url("$base/dashboard/seller.php", 'GET', [], $cookie);
echo "Seller Dashboard Status: " . $res['status'] . " (Expected 200)\n";
if (strpos($res['body'], 'Penjual') !== false) {
    echo "Seller UI loaded successfully.\n";
} else {
    echo "Seller UI failed to load.\n";
}
