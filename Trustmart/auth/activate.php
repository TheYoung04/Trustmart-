<?php
// ============================================================
// auth/activate.php
// Account activation page. Verifies the token and activates the account.
// ============================================================
require_once __DIR__ . '/../config/db.php';

$token = trim($_GET['token'] ?? '');
$success = false;
$message = '';

if ($token !== '') {
    // Check if token exists
    $stmt = $pdo->prepare('SELECT user_id, full_name, is_active FROM users WHERE activation_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        // Activate user
        $stmt = $pdo->prepare('UPDATE users SET is_active = 1, activation_token = NULL WHERE user_id = ?');
        $stmt->execute([$user['user_id']]);

        try {
            $pdo->prepare('CALL recalculate_buyer_trust_score(?)')->execute([$user['user_id']]);
            $pdo->prepare('CALL recalculate_trust_score(?)')->execute([$user['user_id']]);
        } catch (Exception $e) {
            // Silently ignore if procedure LEAVEs due to role
        }
        
        $success = true;
        $message = "Hai " . htmlspecialchars($user['full_name']) . ", akaun anda telah berjaya diaktifkan! Anda kini boleh mula menggunakan platform TrustMart.";
    } else {
        $message = "Pautan pengaktifan tidak sah atau akaun anda sudah diaktifkan sebelum ini.";
    }
} else {
    $message = "Token pengaktifan tidak sah.";
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TrustMart — Pengaktifan Akaun</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --gold: #C9A84C;
      --gold-pale: #FAF6E8;
      --ink: #1A1714;
      --ink3: #6B6358;
      --cream: #FBF8F2;
      --cream2: #F3EFE5;
      --green: #1D6B4A;
      --red: #B03A2E;
      --r: 8px;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--cream);
      color: var(--ink);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .card {
      background: #fff;
      padding: 40px;
      border-radius: var(--r);
      max-width: 480px;
      width: 100%;
      text-align: center;
      box-shadow: 0 4px 24px rgba(26,23,20,0.04);
      border: 1px solid var(--cream2);
    }
    .logo {
      font-family: 'DM Serif Display', serif;
      font-size: 32px;
      margin-bottom: 24px;
    }
    .logo span { color: var(--gold); }
    .icon {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 24px;
      font-size: 32px;
    }
    .icon.success {
      background: #EAF5EE;
      color: var(--green);
    }
    .icon.error {
      background: #FAEAE8;
      color: var(--red);
    }
    .title {
      font-family: 'DM Serif Display', serif;
      font-size: 22px;
      margin-bottom: 12px;
    }
    .text {
      font-size: 14px;
      color: var(--ink3);
      line-height: 1.6;
      margin-bottom: 30px;
    }
    .btn {
      display: inline-block;
      width: 100%;
      padding: 12px;
      background: var(--ink);
      color: #fff;
      border: none;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 500;
      text-decoration: none;
      transition: background 0.15s;
      cursor: pointer;
    }
    .btn:hover {
      background: #3D3830;
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
      <div class="title">Pengaktifan Berjaya!</div>
      <p class="text"><?= htmlspecialchars($message) ?></p>
    <?php else: ?>
      <div class="icon error">✕</div>
      <div class="title">Pengaktifan Gagal</div>
      <p class="text"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    
    <a href="../public/index.php" class="btn">Pergi ke Log Masuk →</a>
  </div>
</body>
</html>
