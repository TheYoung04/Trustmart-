<?php
// ============================================================
// auth/login.php
// Verifies credentials, starts a session, redirects by role
// Returns JSON so the front-end fetch() call can handle it
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json');

// ── Start session safely ──────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie before starting
    session_set_cookie_params([
        'lifetime' => 0,            // expires when browser closes
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off', // HTTPS only on live server
        'httponly' => true,         // not accessible via JavaScript
        'samesite' => 'Strict',     // blocks CSRF from other origins
    ]);
    session_start();
}

// ── Only accept POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Kaedah tidak dibenarkan.']));
}

csrf_validate();

// ── Collect inputs ────────────────────────────────────────────
$email    = strtolower(trim($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';

// ── Basic presence check ──────────────────────────────────────
if ($email === '' || $password === '') {
    http_response_code(422);
    die(json_encode([
        'success' => false,
        'message' => 'Sila masukkan emel dan kata laluan.'
    ]));
}
if (strlen($password) > 128 || strlen($email) > 150) {
    http_response_code(422);
    die(json_encode([
        'success' => false,
        'message' => 'Maklumat log masuk tidak sah.'
    ]));
}

// ── Rate limiting: block after 5 failed attempts in 15 min ───
// Uses PHP session to track attempts — no extra table needed.
// On a production server, use Redis or a login_attempts table instead.
$now = time();
$window = 15 * 60; // 15 minutes in seconds

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}

// Remove attempts older than the window
$_SESSION['login_attempts'] = array_filter(
    $_SESSION['login_attempts'],
    fn($t) => ($now - $t) < $window
);

if (count($_SESSION['login_attempts']) >= 5) {
    $wait = $window - ($now - min($_SESSION['login_attempts']));
    http_response_code(429);
    die(json_encode([
        'success' => false,
        'message' => sprintf(
            'Terlalu banyak percubaan log masuk. Cuba lagi dalam %d minit.',
            ceil($wait / 60)
        )
    ]));
}

// ── Look up the user ──────────────────────────────────────────
// ident_no is the account's own identifier whatever its type: a student's
// matric number or a staff member's staff number. Dashboards print this
// instead of matric_no so a staff account never shows a blank ID.
$stmt = $pdo->prepare(
    'SELECT user_id, full_name, matric_no, staff_no, user_type,
            COALESCE(NULLIF(matric_no, \'\'), staff_no) AS ident_no,
            email, password_hash, role, faculty, is_verified, trust_score, is_active,
            verification_status, activation_token
       FROM users
      WHERE email = ?
      LIMIT 1'
);
$stmt->execute([$email]);
$user = $stmt->fetch();

// ── Verify password ───────────────────────────────────────────
// Always call password_verify even when $user is false.
// This prevents timing attacks that could confirm whether an email exists.
$dummy_hash = '$2y$12$invalidhashusedtoblindtimingattacks000000000000000000000';
$hash_to_check = $user ? $user['password_hash'] : $dummy_hash;

if (!$user || !password_verify($password, $hash_to_check)) {
    // Record the failed attempt
    $_SESSION['login_attempts'][] = $now;

    $remaining = 5 - count($_SESSION['login_attempts']);
    http_response_code(401);
    die(json_encode([
        'success'   => false,
        'message'   => 'Emel atau kata laluan tidak betul.',
        'remaining' => max(0, $remaining),
    ]));
}

// ── Check if account is active ───────────────────────────────
// is_active = 0 covers two different situations, and telling them apart is
// the difference between a useful message and a misleading one:
//   never activated   → activation_token is still set (activate.php clears it
//                       to NULL on success), so the "check your email" advice
//                       is correct.
//   suspended by PTU  → toggle_user_status writes only is_active and leaves
//                       activation_token NULL, so the account WAS activated and
//                       an activation link can no longer help. Sending these
//                       users to look for a link they cannot use is why the
//                       suspension scene looked broken (audit #9).
if ((int) $user['is_active'] === 0) {
    http_response_code(403);
    if ($user['activation_token'] !== null && $user['activation_token'] !== '') {
        die(json_encode([
            'success' => false,
            'message' => 'Akaun anda belum diaktifkan. Sila semak emel anda untuk pautan pengaktifan.'
        ]));
    }
    die(json_encode([
        'success' => false,
        'message' => 'Akaun anda telah digantung oleh PTU. Sila hubungi pentadbir PTU untuk maklumat lanjut atau rayuan.'
    ]));
}

// ── Login successful — clear failed attempts ──────────────────
unset($_SESSION['login_attempts']);

// ── Regenerate session ID to prevent session fixation ─────────
session_regenerate_id(true);

// ── Re-hash if bcrypt cost has been upgraded ───────────────────
// If you increase the cost from 12 to 13 later, old hashes
// get silently upgraded the next time the user logs in.
if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
    $new_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
    $upd->execute([$new_hash, $user['user_id']]);
}

// ── Store safe user data in session ───────────────────────────
// Never store the password_hash in the session.
$_SESSION['user'] = [
    'user_id'             => (int) $user['user_id'],
    'full_name'           => $user['full_name'],
    'matric_no'           => $user['matric_no'],
    'staff_no'            => $user['staff_no'],
    'user_type'           => $user['user_type'],
    'ident_no'            => $user['ident_no'],
    'email'               => $user['email'],
    'role'                => $user['role'],
    'faculty'             => $user['faculty'],
    'is_verified'         => (bool) $user['is_verified'],
    'trust_score'         => (float) $user['trust_score'],
    'verification_status' => $user['verification_status'],
    'is_active'           => true,
];

// ── Determine redirect URL by role ────────────────────────────
$redirects = [
    'pembeli'    => '../dashboard/buyer.php',
    'penjual'    => '../dashboard/seller.php',
    'pentadbir'  => '../dashboard/admin.php',
];

$redirect = $redirects[$user['role']] ?? '../index.php';

// ── Respond ───────────────────────────────────────────────────
echo json_encode([
    'success'  => true,
    'message'  => 'Log masuk berjaya. Sedang mengalihkan...',
    'role'     => $user['role'],
    'redirect' => $redirect,
]);