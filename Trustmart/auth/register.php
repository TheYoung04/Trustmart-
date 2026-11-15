<?php
// ============================================================
// auth/register.php
// Handles new account registration for TrustMart
// Accepts POST from the register form (AJAX or direct submit)
// Returns JSON so it works with both fetch() and form submit
// ============================================================
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// ── Start session for CSRF validation ─────────────────────────
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

// ── Only accept POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Kaedah tidak dibenarkan.']));
}

csrf_validate();

// ── Collect and sanitise inputs ───────────────────────────────
$full_name = trim($_POST['full_name'] ?? '');
$matric_no = strtoupper(trim($_POST['matric_no'] ?? ''));
$staff_no  = strtoupper(trim($_POST['staff_no'] ?? ''));
$email     = strtolower(trim($_POST['email'] ?? ''));
$password  = $_POST['password'] ?? '';
$role      = $_POST['role'] ?? '';
$faculty   = trim($_POST['faculty'] ?? '');

// Account type. Not a role: a staff member is still a pembeli or penjual and
// transacts exactly like a student. Defaults to 'pelajar' so a form submitted
// by an older client (or the AJAX payload before the selector was added) keeps
// working instead of failing with "jenis tidak sah".
$user_type = ($_POST['user_type'] ?? 'pelajar') === 'staf' ? 'staf' : 'pelajar';
$is_staff  = ($user_type === 'staf');

// ── Validate: required fields ─────────────────────────────────
$errors = [];

if ($full_name === '') {
    $errors[] = 'Nama penuh diperlukan.';
} elseif (mb_strlen($full_name) > 100) {
    $errors[] = 'Nama penuh tidak boleh melebihi 100 aksara.';
}

// ── Validate: identity number, one field per account type ─────
// Exactly one of the two columns is ever written, so matric_no keeps meaning a
// matric number and staff_no keeps meaning a staff number.
if ($is_staff) {
    // Deliberately permissive: UTHM staff numbers are not a published format
    // like matric numbers (^[A-Z]{1,2}\d{6,7}$), and an invented strict pattern
    // would lock real staff out. Uniqueness and the email domain do the work.
    if ($staff_no === '') {
        $errors[] = 'No. staf diperlukan untuk akaun staf.';
    } elseif (!preg_match('/^[A-Z0-9][A-Z0-9\-\/.]{2,29}$/', $staff_no)) {
        $errors[] = 'Format no. staf tidak sah. Gunakan 3–30 aksara (huruf, nombor, - / .)';
    }
} elseif ($matric_no === '') {
    $errors[] = 'No. matrik diperlukan.';
} elseif (!preg_match('/^[A-Z]{1,2}\d{6,7}$/', $matric_no)) {
    // Accepts formats like DI240088, DA230102, DC220012
    $errors[] = 'Format no. matrik tidak sah. Contoh: DI240088';
}

// ── Validate: UTHM email only ─────────────────────────────────
// A staff mailbox is the only proof of staff status, so the domain is paired
// with the declared type instead of merely being allowed. '@student.uthm.edu.my'
// does NOT end with '@uthm.edu.my' (the character before "uthm" is ".", not
// "@"), so the two tests below are mutually exclusive.
$is_student_email = str_ends_with($email, '@student.uthm.edu.my');
$is_staff_email   = str_ends_with($email, '@uthm.edu.my');

if ($email === '') {
    $errors[] = 'Emel diperlukan.';
} elseif (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Format emel tidak sah.';
} elseif (!$is_student_email && !$is_staff_email) {
    $errors[] = 'Sila gunakan emel UTHM sahaja (@student.uthm.edu.my atau @uthm.edu.my).';
} elseif ($is_staff && !$is_staff_email) {
    $errors[] = 'Akaun staf memerlukan emel staf UTHM (@uthm.edu.my). Daftar sebagai Pelajar jika anda menggunakan emel pelajar.';
} elseif (!$is_staff && !$is_student_email) {
    $errors[] = 'Emel @uthm.edu.my adalah untuk staf. Tukar jenis akaun kepada Staf UTHM.';
}

// ── Validate: password strength ───────────────────────────────
if (strlen($password) < 8) {
    $errors[] = 'Kata laluan mestilah sekurang-kurangnya 8 aksara.';
} elseif (strlen($password) > 128) {
    $errors[] = 'Kata laluan tidak boleh melebihi 128 aksara.';
} elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    $errors[] = 'Kata laluan mestilah mengandungi huruf dan nombor.';
}

// ── Validate: role ────────────────────────────────────────────
// Public registration only allows pembeli or penjual.
// pentadbir accounts are created manually by IT admin.
$allowed_roles = ['pembeli', 'penjual'];
if (!in_array($role, $allowed_roles, true)) {
    $errors[] = 'Jenis akaun tidak sah. Sila pilih Pembeli atau Penjual.';
}

// PTU is a centre, not a faculty, and it employs staff — so a staff account may
// name it. Other UTHM units are not enumerated here on purpose: inventing an
// org-unit list the proposal never covered would be worse than asking for a
// faculty. Staff from another unit pick their closest faculty.
$allowed_faculties = ['FKEE', 'FKMP', 'FSKTM', 'FKAAB', 'FPTP', 'FPTV', 'PPD'];
if ($is_staff) {
    $allowed_faculties[] = 'PTU';
}
if (!in_array($faculty, $allowed_faculties, true)) {
    $errors[] = $is_staff
        ? 'Sila pilih fakulti atau pusat yang sah.'
        : 'Sila pilih fakulti yang sah.';
}

if (!empty($errors)) {
    http_response_code(422);
    die(json_encode(['success' => false, 'errors' => $errors]));
}

// ── Check for duplicate email or identity number ──────────────
// Only the column this account type actually uses is tested. Comparing the
// empty one would be harmless (SQL never matches NULL with =) but the whitelist
// below also keeps a submitted value out of the identifier position.
$ident_col  = $is_staff ? 'staff_no' : 'matric_no';
$ident_val  = $is_staff ? $staff_no  : $matric_no;
$stmt = $pdo->prepare(
    "SELECT user_id FROM users WHERE email = ? OR {$ident_col} = ? LIMIT 1"
);
$stmt->execute([$email, $ident_val]);

if ($stmt->fetch()) {
    http_response_code(409);
    die(json_encode([
        'success' => false,
        'message' => 'Emel atau no. ' . ($is_staff ? 'pegawai' : 'matrik') . ' sudah didaftarkan. Sila log masuk.'
    ]));
}

// ── Hash the password ─────────────────────────────────────────
// PASSWORD_BCRYPT with cost 12 — safe and fast enough for PHP 8
$password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// ── Generate activation token ─────────────────────────────────
$activation_token = bin2hex(random_bytes(32));

// ── Insert the new user ───────────────────────────────────────
// Fix #7: Buyers start at trust_score=100 (new buyer = perfect score).
// Sellers start at 0 (score builds from real transactions/reviews).
try {
    $initial_trust = ($role === 'pembeli') ? 100.00 : 0.00;
    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, matric_no, staff_no, email, password_hash, role, user_type, faculty, is_active, activation_token, trust_score)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
    );
    $stmt->execute([
        $full_name,
        $is_staff ? null : $matric_no,
        $is_staff ? $staff_no : null,
        $email,
        $password_hash,
        $role,
        $user_type,
        $faculty ?: null,
        $activation_token,
        $initial_trust,
    ]);

    $new_user_id = $pdo->lastInsertId();

    // Return an application-relative link; converted to an absolute URL for
    // email. Avoid deriving a security-sensitive URL from the HTTP Host header.
    $base_path = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
    $activation_link = "{$base_path}/auth/activate.php?token={$activation_token}";

    // Primary delivery channel: email the activation link to the address the
    // user just supplied. The token is NEVER returned to remote callers.
    $mail_body = "Hai {$full_name},\n\n"
        . "Terima kasih kerana mendaftar dengan TrustMart.\n"
        . "Sila aktifkan akaun anda dengan membuka pautan berikut:\n\n"
        . trustmart_absolute_link($activation_link) . "\n\n"
        . "Jika anda tidak membuat pendaftaran ini, sila abaikan emel ini.\n";
    trustmart_send_email($email, 'TrustMart - Aktifkan Akaun Anda', $mail_body);

    $response = [
        'success' => true,
        'message' => 'Akaun berjaya dicipta! Pautan pengaktifan telah dihantar ke emel anda.',
        'user_id' => (int) $new_user_id,
        'role'    => $role,
    ];
    // Local demo convenience only: surface the clickable link when running
    // the development environment on the same machine (dev mode ON + loopback).
    if (trustmart_allow_demo_links()) {
        $response['activation_link'] = $activation_link;
    }

    http_response_code(201);
    echo json_encode($response);

} catch (PDOException $e) {
    error_log('[TrustMart Register Error] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ralat pelayan. Sila cuba lagi.'
    ]);
}