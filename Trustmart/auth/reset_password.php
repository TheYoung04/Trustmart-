<?php
// ============================================================
// auth/reset_password.php
// GET: Shows the reset password form if token is valid.
// POST: Updates the password and clears the token.
// ============================================================
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off',
        'httponly'  => true, 'samesite' => 'Strict',
    ]);
    session_start();
}

require_once __DIR__ . '/../includes/csrf.php';

$token   = trim($_GET['token'] ?? '');
$success = false;
$error   = '';
$showForm = false;

// ── POST: Handle new password submission ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $token    = trim($_POST['token']    ?? '');
    $password = $_POST['password']      ?? '';
    $confirm  = $_POST['password2']     ?? '';

    if ($password !== $confirm) {
        $error = 'Kata laluan baharu dan pengesahan tidak sepadan.';
    } elseif (strlen($password) < 8) {
        $error = 'Kata laluan mestilah sekurang-kurangnya 8 aksara.';
    } elseif (strlen($password) > 128) {
        $error = 'Kata laluan tidak boleh melebihi 128 aksara.';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $error = 'Kata laluan mestilah mengandungi huruf dan nombor.';
    }

    if ($error === '' && $token !== '') {
        $stmt = $pdo->prepare(
            'SELECT user_id, full_name FROM users
              WHERE reset_token = ? AND reset_token_expiry > NOW()
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if ($user) {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare(
                'UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL WHERE user_id = ?'
            )->execute([$hash, $user['user_id']]);

            // Invalidate the current session so any attacker who held the old
            // session ID cannot continue as the user after the password change.
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            $success = true;
        } else {
            $error = 'Pautan telah tamat tempoh atau tidak sah. Sila minta pautan baharu.';
        }
    } elseif ($token === '') {
        $error = 'Token tidak sah.';
    }

    // If there's an error, keep showing the form
    if (!$success && $error !== '' && $token !== '') {
        $showForm = true;
    }
}

// ── GET: Validate token and show form ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($token === '') {
        $error = 'Token tetapan semula tidak sah.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT user_id, full_name FROM users
              WHERE reset_token = ? AND reset_token_expiry > NOW()
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if ($user) {
            $showForm = true;
        } else {
            $error = 'Pautan telah tamat tempoh atau tidak sah. Sila minta pautan baharu.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TrustMart — Tetapan Semula Kata Laluan</title>
  <?= csrf_meta_tag() ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --gold: #C9A84C; --gold-pale: #FAF6E8;
      --ink: #1A1714; --ink3: #6B6358;
      --cream: #FBF8F2; --cream2: #F3EFE5;
      --green: #1D6B4A; --red: #B03A2E;
      --r: 8px;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'DM Sans', sans-serif; background: var(--cream);
      color: var(--ink); min-height: 100vh;
      display: flex; align-items: center; justify-content: center; padding: 20px;
    }
    .card {
      background: #fff; padding: 40px; border-radius: var(--r);
      max-width: 480px; width: 100%; text-align: center;
      box-shadow: 0 4px 24px rgba(26,23,20,0.04);
      border: 1px solid var(--cream2);
    }
    .logo { font-family: 'DM Serif Display', serif; font-size: 32px; margin-bottom: 24px; }
    .logo span { color: var(--gold); }
    .icon {
      width: 64px; height: 64px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 24px; font-size: 32px;
    }
    .icon.success { background: #EAF5EE; color: var(--green); }
    .icon.error   { background: #FAEAE8; color: var(--red); }
    .icon.form    { background: var(--gold-pale); color: var(--gold); }
    .title { font-family: 'DM Serif Display', serif; font-size: 22px; margin-bottom: 12px; }
    .text { font-size: 14px; color: var(--ink3); line-height: 1.6; margin-bottom: 24px; }
    .form-group { margin-bottom: 16px; text-align: left; }
    .form-label { display: block; font-size: 12px; font-weight: 500; margin-bottom: 6px; }
    .form-input {
      width: 100%; padding: 10px 14px; border: 1px solid var(--cream2);
      border-radius: 6px; font-size: 13px; font-family: inherit;
      outline: none; transition: .15s;
    }
    .form-input:focus { border-color: var(--gold); box-shadow: 0 0 0 3px rgba(201,168,76,.1); }
    .btn {
      display: inline-block; width: 100%; padding: 12px;
      background: var(--ink); color: #fff; border: none;
      border-radius: 6px; font-size: 14px; font-weight: 500;
      text-decoration: none; transition: background 0.15s; cursor: pointer;
      font-family: inherit;
    }
    .btn:hover { background: #3D3830; }
    .alert-error {
      background: #FAEAE8; color: var(--red); border: 1px solid #F0B3AD;
      padding: 10px 14px; border-radius: 6px; font-size: 13px;
      margin-bottom: 16px; text-align: left;
    }
  </style>
  <meta name="theme-color" content="#171b26">
  <link rel="stylesheet" href="../public/assets/css/trustmart-ui.css">
  <script src="../public/assets/js/trustmart-ui.js" defer></script>
</head>
<body>
  <div class="card">
    <div class="logo">Trust<span>Mart</span></div>

    <?php if ($success): ?>
      <div class="icon success">✓</div>
      <div class="title">Kata Laluan Berjaya Ditukar!</div>
      <p class="text">Kata laluan anda telah dikemas kini. Sila log masuk dengan kata laluan baharu anda.</p>
      <a href="../public/index.php" class="btn">Pergi ke Log Masuk →</a>

    <?php elseif ($showForm): ?>
      <div class="icon form">🔑</div>
      <div class="title">Tetapan Semula Kata Laluan</div>
      <p class="text">Masukkan kata laluan baharu anda di bawah.</p>

      <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif ?>

      <form method="POST" action="reset_password.php">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <?= csrf_input() ?>

        <div class="form-group">
          <label class="form-label">Kata Laluan Baharu</label>
          <input class="form-input" type="password" name="password"
                 placeholder="Min. 8 aksara" required minlength="8">
        </div>
        <div class="form-group">
          <label class="form-label">Sahkan Kata Laluan</label>
          <input class="form-input" type="password" name="password2"
                 placeholder="Taip semula kata laluan" required>
        </div>
        <button type="submit" class="btn">Tukar Kata Laluan →</button>
      </form>

    <?php else: ?>
      <div class="icon error">✕</div>
      <div class="title">Pautan Tidak Sah</div>
      <p class="text"><?= htmlspecialchars($error) ?></p>
      <a href="../public/index.php" class="btn">Kembali ke Log Masuk →</a>
    <?php endif ?>
  </div>
</body>
</html>
