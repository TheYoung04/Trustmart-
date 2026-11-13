<?php
require_once __DIR__ . '/../config/db.php';
$s = $pdo->query("SELECT user_id, full_name, is_verified, verification_status, trust_score FROM users WHERE email='azwan@student.uthm.edu.my'")->fetch();
print_r($s);
