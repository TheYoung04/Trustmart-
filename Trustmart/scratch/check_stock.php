<?php
require 'config/db.php';
$stmt = $pdo->query('SELECT product_id, stock FROM products');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
