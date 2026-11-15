<?php
// ============================================================
// auth/forgot_password.php
// Generates a password reset token and returns a simulated
// reset link (same approach as activation — no real email).
// ============================================================
require_once __DIR__ . '/../config/db.php';

// Start session for CSRF
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off',
        'httponly'  => true, 'samesite' => 'Strict',
    ]);
    session_start();
}

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mail_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Kaedah tidak dibenarkan.']));
}

csrf_validate();

$email = strtolower(trim($_POST['email'] ?? ''));

if ($email === '' || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    die(json_encode(['success' => false, 'message' => 'Sila masukkan emel yang sah.']));
}

// ── Look up the user ──────────────────────────────────────────
$stmt = $pdo->prepare('SELECT user_id, full_name, is_active FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

// Always return success to prevent email enumeration attacks
if (!$user || (int)$user['is_active'] === 0) {
    echo json_encode([
        'success' => true,
        'message' => 'Jika emel ini berdaftar, pautan tetapan semula kata laluan akan dipaparkan.',
    ]);
    exit;
}

// ── Generate reset token (1 hour expiry) ──────────────────────
$token = bin2hex(random_bytes(32));

// Generate and compare expiry using the database clock so differing PHP and
// database time zones cannot invalidate a fresh link.
$pdo->prepare(
    'UPDATE users
        SET reset_token = ?,
            reset_token_expiry = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)
      WHERE user_id = ?'
)->execute([$token, $user['user_id']]);

// Build an application-relative link; converted to an absolute URL for email.
$base_path  = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$reset_link = "{$base_path}/auth/reset_password.php?token={$token}";

// Primary delivery channel: email the reset link to the account's verified
// address. The token is NEVER returned to remote/anonymous callers.
$mail_body = "Hai {$user['full_name']},\n\n"
    . "Kami menerima permintaan untuk menetapkan semula kata laluan TrustMart anda.\n"
    . "Buka pautan di bawah dalam tempoh 1 jam untuk memilih kata laluan baharu:\n\n"
    . trustmart_absolute_link($reset_link) . "\n\n"
    . "Jika anda tidak membuat permintaan ini, abaikan sahaja emel ini.\n";
trustmart_send_email($email, 'TrustMart - Penetapan Semula Kata Laluan', $mail_body);

$response = [
    'success' => true,
    'message' => 'Jika emel ini berdaftar, pautan tetapan semula telah dihantar ke emel anda.',
];

// Local demo convenience only: surface the clickable link when running the
// development environment on the same machine (dev mode ON + loopback).
if (trustmart_allow_demo_links()) {
    $response['reset_link'] = $reset_link;
}
echo json_encode($response);
