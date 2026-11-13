<?php
require_once __DIR__ . '/../config/db.php';

echo "=== USERS ===\n";
$users = $pdo->query("SELECT user_id, full_name, role, verification_status, email, is_verified, trust_score FROM users")->fetchAll();
foreach ($users as $u) {
    echo "ID: {$u['user_id']} | Name: {$u['full_name']} | Role: {$u['role']} | Status: {$u['verification_status']} | Email: {$u['email']} | Verified: {$u['is_verified']} | Trust: {$u['trust_score']}\n";
}

echo "\n=== PRODUCTS ===\n";
$products = $pdo->query("SELECT product_id, seller_id, name, status FROM products")->fetchAll();
foreach ($products as $p) {
    echo "ID: {$p['product_id']} | SellerID: {$p['seller_id']} | Name: {$p['name']} | Status: {$p['status']}\n";
}
