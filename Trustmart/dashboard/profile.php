<?php
// ============================================================
// dashboard/profile.php
// User profile page — shared by all roles (pembeli, penjual,
// pentadbir). Allows editing name/faculty and changing password.
// ============================================================

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
refresh_session_user($pdo);

require_role(['pembeli', 'penjual', 'pentadbir']);

$user = current_user();

// ════════════════════════════════════════════════════════════
// AJAX ACTION ROUTER
// ════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_validate();

    switch ($_GET['action']) {

        // ── Update profile info ──────────────────────────────
        case 'update_profile':
            $name    = trim($_POST['full_name'] ?? '');
            // Admin (pentadbir) has no faculty selector in the UI. If
            // the POST does not carry a faculty field, keep the stored
            // value instead of silently wiping it to NULL.
            $has_fac = array_key_exists('faculty', $_POST);
            $faculty = trim($_POST['faculty'] ?? '');

            if ($name === '') {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'Nama penuh diperlukan.']));
            }

            if (mb_strlen($name) > 100) {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'Nama terlalu panjang (maksimum 100 aksara).']));
            }

            if ($has_fac) {
                // The same pairing registration enforces: PTU is a centre that
                // employs staff, so a student account cannot claim it. A value
                // outside the list keeps what is stored rather than wiping it.
                $allowed_faculties = ['FKEE','FKMP','FSKTM','FKAAB','FPTP','FPTV','PPD',''];
                if (($user['user_type'] ?? '') === 'staf') {
                    $allowed_faculties[] = 'PTU';
                }
                if (!in_array($faculty, $allowed_faculties, true)) {
                    // Unknown submission (e.g. stale form): keep the stored
                    // faculty instead of silently wiping it to NULL.
                    $faculty = (string) ($user['faculty'] ?? '');
                }

                $pdo->prepare('UPDATE users SET full_name = ?, faculty = ? WHERE user_id = ?')
                    ->execute([$name, $faculty ?: null, $user['user_id']]);
            } else {
                $pdo->prepare('UPDATE users SET full_name = ? WHERE user_id = ?')
                    ->execute([$name, $user['user_id']]);
            }

            refresh_session_user($pdo);
            echo json_encode(['success' => true, 'message' => 'Profil berjaya dikemas kini.']);
            exit;

        // ── Change password ──────────────────────────────────
        case 'change_password':
            $current = $_POST['current_password'] ?? '';
            $new_pw  = $_POST['new_password']     ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if ($current === '' || $new_pw === '') {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'Sila isi semua medan.']));
            }

            if ($new_pw !== $confirm) {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'Kata laluan baharu tidak sepadan.']));
            }

            if (strlen($new_pw) < 8) {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'Kata laluan mestilah sekurang-kurangnya 8 aksara.']));
            }

            if (strlen($new_pw) > 128) {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'Kata laluan tidak boleh melebihi 128 aksara.']));
            }

            if (!preg_match('/[A-Za-z]/', $new_pw) || !preg_match('/[0-9]/', $new_pw)) {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'Kata laluan mestilah mengandungi huruf dan nombor.']));
            }

            // Verify current password
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
            $stmt->execute([$user['user_id']]);
            $hash = $stmt->fetchColumn();

            if (!password_verify($current, $hash)) {
                http_response_code(401);
                die(json_encode(['success' => false, 'message' => 'Kata laluan semasa tidak betul.']));
            }

            $new_hash = password_hash($new_pw, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                ->execute([$new_hash, $user['user_id']]);

            echo json_encode(['success' => true, 'message' => 'Kata laluan berjaya ditukar!']);
            exit;

        // ── Apply to become a seller (pembeli → penjual) ─────
        // Mirrors the registration path: the account becomes a penjual in
        // 'Menunggu' state and must be approved by PTU in the existing
        // Pengesahan Penjual queue before any product can be added.
        // Pembeli history (trust profile, orders) is untouched.
        case 'request_seller_role':
            $is_pembeli   = ($user['role'] === 'pembeli');
            $rejected_again = ($user['role'] === 'penjual'
                               && ($user['verification_status'] ?? '') === 'Ditolak');
            if (!$is_pembeli && !$rejected_again) {
                http_response_code(403);
                die(json_encode(['success' => false, 'message' => 'Akaun ini tidak layak memohon menjadi penjual.']));
            }

            $pdo->prepare(
                "UPDATE users SET role = 'penjual', verification_status = 'Menunggu', is_verified = 0
                 WHERE user_id = ?"
            )->execute([$user['user_id']]);
            refresh_session_user($pdo);

            echo json_encode([
                'success'  => true,
                'message'  => 'Permohonan dihantar! Selepas kelulusan PTU, anda boleh mula menjual.',
                'redirect' => 'seller.php',
            ]);
            exit;

        default:
            http_response_code(400);
            die(json_encode(['success' => false, 'message' => 'Tindakan tidak diketahui.']));
    }
}

// ── Page helpers ──────────────────────────────────────────────
$role_label = match($user['role']) {
    'pembeli'   => 'Pembeli',
    'penjual'   => 'Penjual',
    'pentadbir' => 'Pentadbir PTU',
    default     => $user['role'],
};

$dashboard_url = match($user['role']) {
    'pembeli'   => 'buyer.php',
    'penjual'   => 'seller.php',
    'pentadbir' => 'admin.php',
    default     => 'buyer.php',
};

// ── Mode-aware return link ────────────────────────────────────
// A penjual account can browse either side of the app: seller.php
// (Mod Penjual) or buyer.php (Mod Pembeli). If they open Profil Saya
// from Mod Pembeli, "Kembali ke Papan Pemuka" must take them back to
// the SAME mode they came from — not silently drop them into
// Mod Penjual. Honour the ?mode= hint, falling back to the Referer
// for old links / bookmarks.
if ($user['role'] === 'penjual') {
    $__mode = strtolower((string) ($_GET['mode'] ?? ''));
    if ($__mode === '') {
        $__ref = strtolower((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if (str_contains($__ref, '/buyer.php')) {
            $__mode = 'pembeli';
        } elseif (str_contains($__ref, '/seller.php')) {
            $__mode = 'penjual';
        }
    }
    if ($__mode === 'pembeli') {
        $dashboard_url = 'buyer.php';
        $role_label    = 'Penjual (Mod Pembeli)';
    }
}

function trust_color_p(float $score): string {
    if ($score >= 85) return 'var(--green)';
    if ($score >= 70) return 'var(--gold)';
    return 'var(--red)';
}

// Query registration date
$stmt = $pdo->prepare('SELECT created_at, buyer_trust_score FROM users WHERE user_id = ?');
$stmt->execute([$user['user_id']]);
$profile_row = $stmt->fetch() ?: [];
$created_at  = $profile_row['created_at'] ?? null;
// Seller accounts also buy, so they have a separate buyer score.
$buyer_score = (float) ($profile_row['buyer_trust_score'] ?? $user['trust_score']);

// ── Unified trust display (matches catalogue / product / chat convention) ──
// The role-specific Bayesian profile in trust_profiles is the single scoring
// authority. A user without completed evidence shows "Baharu", never a
// misleading legacy users.trust_score 0.0/100 mirror.
require_once __DIR__ . '/../includes/trust_score.php';
$primary_profile = trust_profile($pdo, (int) $user['user_id'],
                                 $user['role'] === 'penjual' ? 'penjual' : 'pembeli');
$buyer_profile   = trust_profile($pdo, (int) $user['user_id'], 'pembeli');
?>
<!DOCTYPE html>
<html lang="ms">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TrustMart — Profil Saya</title>
  <?= csrf_meta_tag() ?>
  <?= csrf_script() ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <style>
    :root{
      --gold:#C9A84C;--gold-pale:#FAF6E8;--gold-light:#F0DFA0;
      --ink:#1A1714;--ink2:#3D3830;--ink3:#6B6358;--ink4:#A09589;
      --cream:#FBF8F2;--cream2:#F3EFE5;--cream3:#EAE4D6;
      --green:#1D6B4A;--green-bg:#EAF5EE;
      --red:#B03A2E;--red-bg:#FAEAE8;
      --blue:#1A4F8A;--blue-bg:#EAF0FA;
      --r:10px;--r-sm:6px;
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);
         color:var(--ink);font-size:14px}

    .shell  {display:flex;height:100vh;overflow:hidden}
    .sidebar{width:220px;background:var(--ink);flex-shrink:0;
             display:flex;flex-direction:column;padding:24px 0}
    .main   {flex:1;overflow-y:auto}
    .topbar {height:60px;background:#fff;border-bottom:1px solid var(--cream3);
             display:flex;align-items:center;padding:0 28px;gap:16px;
             position:sticky;top:0;z-index:10}
    .content{padding:28px;max-width:720px}

    .logo-text{font-family:'DM Serif Display',serif;font-size:22px;color:#fff;
               padding:0 20px 24px;border-bottom:1px solid rgba(255,255,255,.08)}
    .logo-text span{color:var(--gold)}
    .nav-section{padding:20px 12px 8px;font-size:10px;letter-spacing:1.5px;
                 text-transform:uppercase;color:rgba(255,255,255,.3)}
    .nav-item{display:flex;align-items:center;gap:10px;padding:9px 20px;
              color:rgba(255,255,255,.55);cursor:pointer;font-size:13px;
              border-left:2px solid transparent;transition:all .15s;
              text-decoration:none}
    .nav-item:hover{color:#fff;background:rgba(255,255,255,.06)}
    .nav-item.active{color:#fff;background:rgba(201,168,76,.15);
                     border-left-color:var(--gold)}
    .sidebar-footer{margin-top:auto;padding:16px 20px;
                    border-top:1px solid rgba(255,255,255,.08)}
    .avatar{width:32px;height:32px;border-radius:50%;background:var(--gold);
            display:flex;align-items:center;justify-content:center;
            font-size:12px;font-weight:600;color:var(--ink)}
    .avatar-lg{width:64px;height:64px;font-size:24px;border-radius:50%;
               background:var(--gold);display:flex;align-items:center;
               justify-content:center;font-weight:600;color:var(--ink)}

    .card-box{background:#fff;border:1px solid var(--cream3);
              border-radius:var(--r);padding:24px;margin-bottom:20px}
    .card-title{font-family:'DM Serif Display',serif;font-size:18px;
                margin-bottom:4px}
    .card-sub{font-size:12px;color:var(--ink3);margin-bottom:20px}

    .info-row{display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:13px}
    .info-label{width:140px;color:var(--ink3);font-size:12px;flex-shrink:0}
    .info-val{font-weight:500}

    .form-group{margin-bottom:16px}
    .form-label{display:block;font-size:12px;font-weight:500;margin-bottom:6px}
    .form-input{width:100%;padding:10px 14px;border:1px solid var(--cream3);
                border-radius:var(--r-sm);font-size:13px;font-family:inherit;
                outline:none;background:#fff;color:var(--ink);transition:.15s}
    .form-input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,168,76,.1)}
    .form-input:disabled{background:var(--cream);color:var(--ink3);cursor:not-allowed}
    select.form-input{-webkit-appearance:none;cursor:pointer}

    .btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;
         border-radius:var(--r-sm);border:1px solid var(--cream3);background:#fff;
         color:var(--ink);font-size:12px;font-weight:500;cursor:pointer;
         transition:.15s;font-family:inherit}
    .btn:hover{border-color:var(--gold);color:var(--gold)}
    .btn-primary{background:var(--gold);border-color:var(--gold);color:var(--ink)}
    .btn-primary:hover{background:#b8942f;border-color:#b8942f}
    .btn:disabled{opacity:.5;cursor:not-allowed}

    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;
           border-radius:20px;font-size:11px;font-weight:500}
    .badge-green{background:var(--green-bg);color:var(--green)}
    .badge-gold{background:var(--gold-pale);color:#7A5A10}
    .badge-red{background:var(--red-bg);color:var(--red)}
    .badge-blue{background:var(--blue-bg);color:var(--blue)}

    .divider{height:1px;background:var(--cream2);margin:20px 0}

    .toast{position:fixed;bottom:24px;right:24px;background:var(--ink);color:#fff;
           padding:12px 18px;border-radius:var(--r);font-size:13px;z-index:200;
           transform:translateY(100px);opacity:0;transition:all .3s;max-width:320px}
    .toast.show{transform:translateY(0);opacity:1}
    .toast.success{background:var(--green)}.toast.error{background:var(--red)}

    @media(max-width:768px){
      .sidebar{display:none}
      .content{padding:20px}
    }
  </style>
  <meta name="theme-color" content="#171b26">
  <link rel="stylesheet" href="../public/assets/css/trustmart-ui.css">
  <script src="../public/assets/js/trustmart-ui.js" defer></script>
</head>
<body>
<div class="shell">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="logo-text">Trust<span>Mart</span></div>

    <div class="nav-section">Akaun</div>
    <a class="nav-item" href="<?= $dashboard_url ?>" style="text-decoration:none">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/>
      </svg>Kembali ke Papan Pemuka
    </a>
    <div class="nav-item active">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>Profil Saya
    </div>

    <div class="sidebar-footer">
      <div style="display:flex;align-items:center;gap:10px">
        <div class="avatar"><?= htmlspecialchars(mb_substr($user['full_name'],0,1)) ?></div>
        <div>
          <div style="font-size:12px;color:rgba(255,255,255,.8)">
            <?= htmlspecialchars($user['full_name']) ?>
          </div>
          <div style="font-size:10px;color:rgba(255,255,255,.35)">
            <?= $role_label ?><?= ($user['user_type'] ?? '') === 'staf' ? ' · Staf' : '' ?> · <?= htmlspecialchars($user['ident_no']) ?>
          </div>
        </div>
      </div>
      <div style="margin-top:12px">
        <a href="../auth/logout.php"
           style="display:flex;align-items:center;gap:8px;padding:7px 0;
                  font-size:12px;color:rgba(255,255,255,.4);text-decoration:none">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
               viewBox="0 0 24 24">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>Log Keluar
        </a>
      </div>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="main">
    <div class="topbar">
      <div style="font-family:'DM Serif Display',serif;font-size:16px">Profil Saya</div>
      <div style="margin-left:auto;font-size:12px;color:var(--ink3)">
        <?= $role_label ?><?= ($user['user_type'] ?? '') === 'staf' ? ' · Staf' : '' ?> · <?= htmlspecialchars($user['ident_no']) ?>
      </div>
    </div>

    <div class="content">

      <!-- PROFILE CARD -->
      <div class="card-box">
        <div style="display:flex;align-items:center;gap:20px;margin-bottom:20px">
          <div class="avatar-lg"><?= htmlspecialchars(mb_substr($user['full_name'],0,1)) ?></div>
          <div>
            <div style="font-size:18px;font-weight:600"><?= htmlspecialchars($user['full_name']) ?></div>
            <div style="font-size:12px;color:var(--ink3);margin-top:2px">
              <?= htmlspecialchars($user['email']) ?>
            </div>
            <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap">
              <span class="badge badge-blue"><?= $role_label ?></span>
              <?php if ($user['role'] === 'penjual'): ?>
                <?php if ($user['verification_status'] === 'Disahkan'): ?>
                  <span class="badge badge-green">✓ Disahkan PTU</span>
                <?php elseif ($user['verification_status'] === 'Ditolak'): ?>
                  <span class="badge badge-red">✕ Ditolak</span>
                <?php else: ?>
                  <span class="badge badge-gold">⏳ Menunggu Pengesahan</span>
                <?php endif ?>
              <?php endif ?>
            </div>
          </div>
        </div>

        <div class="divider"></div>

        <div class="info-row">
          <div class="info-label">Jenis Akaun</div>
          <!-- Chosen once at registration and deliberately not editable: the
               identifier, the email domain and the trust history below this
               account all depend on it. Changing it would silently re-attribute
               a staff member's record as a student's. -->
          <div class="info-val"><?= ($user['user_type'] ?? '') === 'staf' ? 'Staf UTHM' : 'Pelajar UTHM' ?></div>
        </div>
        <div class="info-row">
          <div class="info-label"><?= ($user['user_type'] ?? '') === 'staf' ? 'No. Staf' : 'No. Matrik' ?></div>
          <div class="info-val"><?= htmlspecialchars($user['ident_no']) ?></div>
        </div>
        <div class="info-row">
          <div class="info-label">Emel</div>
          <div class="info-val"><?= htmlspecialchars($user['email']) ?></div>
        </div>
        <?php if ($user['role'] !== 'pentadbir'): ?>
        <div class="info-row">
          <!-- Staf are attached to a centre (PTU) rather than a faculty, so the
               label follows the account type — same wording as the register form. -->
          <div class="info-label"><?= ($user['user_type'] ?? '') === 'staf' ? 'Fakulti / Pusat' : 'Fakulti' ?></div>
          <div class="info-val"><?= htmlspecialchars($user['faculty'] ?? '—') ?></div>
        </div>
        <?php endif ?>
        <?php if ($user['role'] !== 'pentadbir'): ?>
        <div class="info-row">
          <div class="info-label"><?= $user['role'] === 'penjual' ? 'Skor Penjual' : 'Skor Kepercayaan' ?></div>
          <div class="info-val" style="color:<?= trust_score_color($primary_profile) ?>">
            <?= trust_score_display($primary_profile) ?>
          </div>
        </div>
        <?php if ($user['role'] === 'penjual'): ?>
        <div class="info-row">
          <div class="info-label">Skor Pembeli</div>
          <div class="info-val" style="color:<?= trust_score_color($buyer_profile) ?>">
            <?= trust_score_display($buyer_profile) ?>
          </div>
        </div>
        <?php endif ?>
        <?php endif ?>
        <div class="info-row">
          <div class="info-label">Tarikh Daftar</div>
          <div class="info-val"><?= $created_at ? date('d M Y', strtotime($created_at)) : '—' ?></div>
        </div>
      </div>

      <?php $can_apply_seller = ($user['role'] === 'pembeli')
          || ($user['role'] === 'penjual' && ($user['verification_status'] ?? '') === 'Ditolak'); ?>
      <?php if ($can_apply_seller): ?>
      <!-- BECOME A SELLER -->
      <div class="card-box" style="border:1px solid var(--gold-light)">
        <div class="card-title"><?= $user['role'] === 'pembeli' ? 'Ingin Menjual?' : 'Mohon Semula Sebagai Penjual' ?></div>
        <div class="card-sub">
          <?= $user['role'] === 'pembeli'
              ? 'Akaun semasa anda hanya boleh membeli. Anda boleh memohon untuk menjadi penjual — permohonan disemak oleh pentadbir PTU.'
              : 'Permohonan penjual anda sebelum ini telah ditolak. Anda boleh menghantar permohonan semula untuk semakan PTU.' ?>
        </div>
        <ol style="font-size:12px;color:var(--ink3);margin:0 0 16px 18px;line-height:1.9">
          <li>Hantar permohonan di bawah — sejarah pembelian &amp; skor anda <b>kekal</b>.</li>
          <li>Pentadbir PTU menyemak permohonan (anda nampak status di Papan Pemuka Penjual).</li>
          <li>Selepas <b>Disahkan PTU</b>, anda boleh tambah produk &amp; mula menjual.</li>
        </ol>
        <div style="display:flex;justify-content:flex-end">
          <button class="btn btn-primary" id="become-seller-btn" onclick="requestSellerRole()">
            <?= $user['role'] === 'pembeli' ? 'Mohon Menjadi Penjual' : 'Hantar Permohonan Semula' ?>
          </button>
        </div>
      </div>
      <?php endif ?>

      <!-- EDIT PROFILE -->
      <div class="card-box">
        <div class="card-title">Kemas Kini Profil</div>
        <?php
        $prof_is_staff = ($user['user_type'] ?? '') === 'staf';
        $prof_fac_label = $prof_is_staff ? 'Fakulti / Pusat' : 'Fakulti';
        $prof_fac_pick  = $prof_is_staff ? 'Pilih Fakulti / Pusat' : 'Pilih Fakulti';
        ?>
        <div class="card-sub"><?php
            if ($user['role'] === 'pentadbir')      echo 'Tukar nama penuh anda.';
            elseif ($user['user_type'] === 'staf')  echo 'Tukar nama penuh atau fakulti/pusat anda.';
            else                                    echo 'Tukar nama penuh atau fakulti anda.';
        ?></div>

        <div class="form-group">
          <label class="form-label">Nama Penuh</label>
          <input class="form-input" type="text" id="edit-name"
                 value="<?= htmlspecialchars($user['full_name']) ?>">
        </div>
        <?php if ($user['role'] !== 'pentadbir'): ?>
        <div class="form-group">
          <label class="form-label"><?= $prof_fac_label ?></label>
          <select class="form-input" id="edit-faculty">
            <option value=""><?= $prof_fac_pick ?></option>
            <?php
            // PTU is offered to staff accounts only — matching the register form.
            $faculties = ['FKEE','FKMP','FSKTM','FKAAB','FPTP','FPTV','PPD'];
            if ($prof_is_staff) $faculties[] = 'PTU';
            // Show the stored faculty even if it is not in the list, so
            // editing the name never drops an unlisted value.
            $current_faculty = trim((string)($user['faculty'] ?? ''));
            if ($current_faculty !== '' && !in_array($current_faculty, $faculties, true)) {
                $faculties[] = $current_faculty;
            }
            foreach ($faculties as $f): ?>
            <option value="<?= $f ?>" <?= $current_faculty === $f ? 'selected' : '' ?>><?= $f ?></option>
            <?php endforeach ?>
          </select>
        </div>
        <?php endif ?>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Emel (tidak boleh ditukar)</label>
          <input class="form-input" type="email" disabled
                 value="<?= htmlspecialchars($user['email']) ?>">
        </div>

        <div style="margin-top:20px;display:flex;justify-content:flex-end">
          <button class="btn btn-primary" id="save-profile-btn" onclick="saveProfile()">
            Simpan Perubahan
          </button>
        </div>
      </div>

      <!-- CHANGE PASSWORD -->
      <div class="card-box">
        <div class="card-title">Tukar Kata Laluan</div>
        <div class="card-sub">Masukkan kata laluan semasa dan kata laluan baharu anda.</div>

        <div class="form-group">
          <label class="form-label">Kata Laluan Semasa</label>
          <input class="form-input" type="password" id="pw-current"
                 placeholder="Masukkan kata laluan semasa">
        </div>
        <div class="form-group">
          <label class="form-label">Kata Laluan Baharu</label>
          <input class="form-input" type="password" id="pw-new"
                 placeholder="Min. 8 aksara, huruf + nombor">
        </div>
        <div class="form-group">
          <label class="form-label">Sahkan Kata Laluan Baharu</label>
          <input class="form-input" type="password" id="pw-confirm"
                 placeholder="Taip semula kata laluan baharu">
        </div>

        <div style="margin-top:20px;display:flex;justify-content:flex-end">
          <button class="btn btn-primary" id="change-pw-btn" onclick="changePassword()">
            Tukar Kata Laluan
          </button>
        </div>
      </div>

    </div><!-- end .content -->
  </div><!-- end .main -->
</div><!-- end .shell -->

<div class="toast" id="toast"></div>

<script>
function showToast(msg, type = '') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = `toast show ${type}`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 3200);
}

async function saveProfile() {
  const btn    = document.getElementById('save-profile-btn');
  const name   = document.getElementById('edit-name').value.trim();
  const facEl  = document.getElementById('edit-faculty');
  // Admin (pentadbir) has no faculty dropdown — PTU staff are not
  // attached to a faculty. Only send the field when the UI shows it
  // so a save from the admin profile does not blank an existing value.
  const fac    = facEl ? facEl.value : null;

  if (!name) { showToast('Nama penuh diperlukan.', 'error'); return; }

  btn.disabled = true;
  btn.textContent = 'Menyimpan...';

  const payload = { full_name: name };
  if (fac !== null) payload.faculty = fac;

  try {
    const res = await fetch('?action=update_profile', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(payload),
    });
    const data = await res.json();
    showToast(data.message, data.success ? 'success' : 'error');
    if (data.success) setTimeout(() => location.reload(), 1000);
  } catch (e) {
    showToast('Ralat rangkaian.', 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Simpan Perubahan';
  }
}

async function requestSellerRole() {
  const ok = confirm('Sahkan permohonan menjadi penjual? Akaun anda akan ditukar kepada Penjual dan perlu disahkan oleh PTU sebelum boleh menjual.');
  if (!ok) return;
  const btn = document.getElementById('become-seller-btn');
  btn.disabled = true;
  btn.textContent = 'Menghantar...';
  try {
    const res = await fetch('?action=request_seller_role', { method: 'POST' });
    const data = await res.json();
    showToast(data.message, data.success ? 'success' : 'error');
    if (data.success) setTimeout(() => location.href = data.redirect, 1200);
    else { btn.disabled = false; btn.textContent = 'Mohon Menjadi Penjual'; }
  } catch (e) {
    showToast('Ralat rangkaian.', 'error');
    btn.disabled = false;
  }
}

async function changePassword() {
  const btn     = document.getElementById('change-pw-btn');
  const current = document.getElementById('pw-current').value;
  const newPw   = document.getElementById('pw-new').value;
  const confirm = document.getElementById('pw-confirm').value;

  if (!current || !newPw) { showToast('Sila isi semua medan.', 'error'); return; }
  if (newPw !== confirm)  { showToast('Kata laluan baharu tidak sepadan.', 'error'); return; }
  if (newPw.length < 8)   { showToast('Kata laluan min. 8 aksara.', 'error'); return; }

  btn.disabled = true;
  btn.textContent = 'Menukar...';

  try {
    const res = await fetch('?action=change_password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        current_password: current,
        new_password: newPw,
        confirm_password: confirm,
      }),
    });
    const data = await res.json();
    showToast(data.message, data.success ? 'success' : 'error');
    if (data.success) {
      document.getElementById('pw-current').value = '';
      document.getElementById('pw-new').value = '';
      document.getElementById('pw-confirm').value = '';
    }
  } catch (e) {
    showToast('Ralat rangkaian.', 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Tukar Kata Laluan';
  }
}
</script>
</body>
</html>
