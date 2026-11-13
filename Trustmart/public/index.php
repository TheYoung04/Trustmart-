<?php
// ============================================================
// public/index.php
// Public authentication entry point for TrustMart.
// ============================================================
require_once __DIR__ . '/../includes/auth_check.php';

// If already logged in, send the user to the correct dashboard.
if (is_logged_in()) {
    $role_redirects = [
        'pembeli'   => '../dashboard/buyer.php',
        'penjual'   => '../dashboard/seller.php',
        'pentadbir' => '../dashboard/admin.php',
    ];
    $role = $_SESSION['user']['role'] ?? '';
    if (isset($role_redirects[$role])) {
        header('Location: ' . $role_redirects[$role]);
        exit;
    }

    // Do not keep an invalid role in an authenticated session.
    unset($_SESSION['user']);
}

/**
 * Accept only a local relative path as a post-login destination.
 * This prevents a crafted login URL from redirecting users off-site.
 */
function safe_login_redirect(string $target): string
{
    $target = trim($target);
    if ($target === '' || strlen($target) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $target)) {
        return '';
    }

    $normalised = str_replace('\\', '/', $target);
    $parts = parse_url($normalised);
    if ($parts === false
        || isset($parts['scheme'])
        || isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || str_starts_with($normalised, '//')) {
        return '';
    }

    $path = $parts['path'] ?? '';
    if ($path === '' || preg_match('#(^|/)\.\.(/|$)#', $path)) {
        return '';
    }

    return $normalised;
}

$redirect_to = safe_login_redirect((string)($_GET['redirect'] ?? ''));
?>
<!DOCTYPE html>
<html lang="ms">
<head>
  <meta charset="UTF-8">
  <?= csrf_meta_tag() ?>
  <?= csrf_script() ?>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#171b26">
  <meta name="description" content="TrustMart ialah pasaran kampus UTHM yang selamat untuk komuniti pelajar dan staf.">
  <title>TrustMart — Log Masuk</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --gold:#C9A84C; --gold-pale:#FAF6E8;
      --ink:#1A1714; --ink3:#6B6358; --ink4:#8A8176;
      --cream:#FBF8F2; --cream2:#F3EFE5; --cream3:#EAE4D6;
      --green:#1D6B4A; --red:#B03A2E; --red-bg:#FAEAE8;
      --r-sm:8px;
    }
    *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
    [hidden] { display:none !important; }
    body {
      min-height:100vh; display:flex; background:var(--cream); color:var(--ink);
      font-family:'DM Sans',sans-serif;
    }
    .login-left {
      position:relative; flex:1; display:flex; flex-direction:column;
      justify-content:center; min-height:100vh; padding:clamp(42px,7vw,88px);
      overflow:hidden;
    }
    .login-right {
      width:460px; display:flex; flex-direction:column; justify-content:center;
      padding:56px 52px; background:#fff;
    }
    .logo { margin-bottom:8px; color:#fff; font-family:'DM Serif Display',serif; font-size:48px; }
    .logo span { color:var(--gold); }
    .tagline {
      max-width:420px; margin-bottom:38px; color:rgba(255,255,255,.58);
      font-size:14px; line-height:1.75;
    }
    .feature { position:relative; z-index:1; display:flex; align-items:flex-start; gap:12px; margin-bottom:12px; }
    .feat-icon {
      display:flex; width:34px; height:34px; flex-shrink:0; align-items:center;
      justify-content:center; border-radius:8px; background:rgba(201,168,76,.15);
    }
    .feat-title { margin-bottom:2px; color:rgba(255,255,255,.9); font-size:13px; font-weight:600; }
    .feat-text { color:rgba(255,255,255,.55); font-size:12px; line-height:1.55; }
    .eyebrow {
      margin-bottom:9px; color:var(--gold); font-size:10px; font-weight:700;
      letter-spacing:1.5px; text-transform:uppercase;
    }
    .panel-title { margin-bottom:5px; font-size:24px; font-weight:600; }
    .panel-sub { margin-bottom:26px; color:var(--ink3); font-size:13px; }
    .tabs { display:flex; margin-bottom:22px; padding:3px; border-radius:8px; background:var(--cream); }
    .tab {
      flex:1; padding:8px; border:0; border-radius:7px; background:transparent;
      color:var(--ink3); font-size:12px; font-weight:600; cursor:pointer;
    }
    .tab.active { background:#fff; color:var(--ink); box-shadow:0 1px 5px rgba(26,23,20,.08); }
    .form-group { margin-bottom:16px; }
    .form-label { display:block; margin-bottom:6px; font-size:12px; font-weight:600; }
    .form-input {
      width:100%; padding:10px 14px; border:1px solid var(--cream3);
      border-radius:var(--r-sm); outline:none; background:#fff; color:var(--ink);
      font:inherit; font-size:13px;
    }
    .form-input::placeholder { color:var(--ink4); }
    .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .btn-submit {
      display:flex; width:100%; min-height:44px; align-items:center; justify-content:center;
      gap:8px; padding:11px; border:0; border-radius:var(--r-sm); background:var(--ink);
      color:#fff; font-size:14px; font-weight:600; cursor:pointer;
    }
    .btn-submit:disabled { opacity:.6; cursor:not-allowed; }
    .form-note { margin-top:15px; color:var(--ink4); font-size:11px; line-height:1.5; text-align:center; }
    .forgot { display:flex; justify-content:flex-end; margin:-7px 0 16px; }
    .forgot button, .modal-close {
      padding:3px; border:0; background:transparent; color:var(--gold);
      font-size:12px; text-decoration:underline; cursor:pointer;
    }
    .alert {
      display:none; margin-bottom:16px; padding:11px 13px; border-radius:8px;
      font-size:13px; line-height:1.55;
    }
    .alert.show { display:block; }
    .alert-error { border:1px solid #F0B3AD; background:var(--red-bg); color:var(--red); }
    .alert-success { border:1px solid #A8D9BB; background:#EAF5EE; color:var(--green); }
    .alert a { color:inherit; font-weight:700; }
    .spinner {
      width:14px; height:14px; flex-shrink:0; border:2px solid rgba(255,255,255,.3);
      border-top-color:#fff; border-radius:50%; animation:spin .6s linear infinite;
    }
    @keyframes spin { to { transform:rotate(360deg); } }
    .password-meter { display:flex; gap:4px; margin-top:7px; }
    .password-meter span { height:3px; flex:1; border-radius:3px; background:var(--cream3); transition:.2s; }
    .password-meter[data-score="1"] span:nth-child(-n+1) { background:var(--red); }
    .password-meter[data-score="2"] span:nth-child(-n+2) { background:#D68724; }
    .password-meter[data-score="3"] span:nth-child(-n+3) { background:var(--gold); }
    .password-meter[data-score="4"] span:nth-child(-n+4) { background:var(--green); }
    .password-hint { margin-top:5px; color:var(--ink4); font-size:10px; }
    .form-hint { margin-top:5px; color:var(--ink3); font-size:11px; line-height:1.4; }
    .auth-modal {
      position:fixed; inset:0; z-index:100; display:none; align-items:center;
      justify-content:center; padding:18px; background:rgba(26,23,20,.56);
    }
    .auth-modal-card { width:420px; max-width:100%; padding:28px; border-radius:14px; background:#fff; }
    .auth-modal-title { margin-bottom:4px; font-family:'DM Serif Display',serif; font-size:21px; }
    .auth-modal-sub { margin-bottom:20px; color:var(--ink3); font-size:12px; line-height:1.6; }
    .modal-actions { margin-top:16px; text-align:center; }
    @media (max-width:768px) {
      body { flex-direction:column; }
      .login-left { min-height:auto; padding:38px 28px; }
      .login-right { width:100%; padding:38px 28px 48px; }
    }
    @media (max-width:480px) {
      .form-grid { grid-template-columns:1fr; gap:0; }
    }
  </style>
  <link rel="stylesheet" href="assets/css/trustmart-ui.css">
  <script src="assets/js/trustmart-ui.js" defer></script>
</head>
<body>

<section class="login-left" aria-labelledby="brand-title">
  <div class="logo" id="brand-title">Trust<span>Mart</span></div>
  <p class="tagline">Pasaran kampus UTHM yang selamat dan dipercayai. Beli dan jual dengan yakin melalui identiti yang disahkan dan skor kepercayaan telus.</p>

  <div class="feature">
    <div class="feat-icon" aria-hidden="true">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#C9A84C" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    </div>
    <div><div class="feat-title">Skor Kepercayaan Telus</div><div class="feat-text">Rekod transaksi, ulasan dan prestasi membantu anda membuat pilihan.</div></div>
  </div>

  <div class="feature">
    <div class="feat-icon" aria-hidden="true">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#C9A84C" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m8.5 12 2.2 2.2 4.8-4.8"/></svg>
    </div>
    <div><div class="feat-title">Disahkan PTU UTHM</div><div class="feat-text">Status pengesahan penjual sentiasa dipaparkan sebelum pembelian.</div></div>
  </div>

  <div class="feature">
    <div class="feat-icon" aria-hidden="true">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#C9A84C" stroke-width="2"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg>
    </div>
    <div><div class="feat-title">Bantuan & Aduan</div><div class="feat-text">Berhubung dengan penjual dan hantar aduan terus kepada pentadbir.</div></div>
  </div>
</section>

<main class="login-right" id="main-content">
  <div class="eyebrow">Komuniti UTHM</div>
  <h1 class="panel-title">Selamat Kembali</h1>
  <p class="panel-sub">Gunakan akaun UTHM anda untuk meneruskan.</p>

  <div class="tabs" role="tablist" aria-label="Log masuk atau daftar">
    <button type="button" class="tab active" id="tab-login-btn" role="tab" aria-selected="true" aria-controls="form-login">Log Masuk</button>
    <button type="button" class="tab" id="tab-reg-btn" role="tab" aria-selected="false" aria-controls="form-register" tabindex="-1">Daftar Akaun</button>
  </div>

  <div class="alert alert-error" id="alert-error" role="alert" aria-live="assertive"></div>
  <div class="alert alert-success" id="alert-success" role="status" aria-live="polite"></div>

  <form id="form-login" novalidate>
    <div class="form-group">
      <label class="form-label" for="li-email">Emel UTHM</label>
      <input class="form-input" type="email" id="li-email" name="email"
             placeholder="nama@student.uthm.edu.my" autocomplete="email" inputmode="email" required>
    </div>
    <div class="form-group">
      <label class="form-label" for="li-pass">Kata Laluan</label>
      <input class="form-input" type="password" id="li-pass" name="password"
             placeholder="Masukkan kata laluan" autocomplete="current-password" required>
    </div>
    <div class="forgot"><button type="button" id="forgot-open">Lupa kata laluan?</button></div>
    <button type="submit" class="btn-submit" id="login-btn">Log Masuk <span aria-hidden="true">→</span></button>
    <p class="form-note">Emel mestilah berakhir dengan @student.uthm.edu.my atau @uthm.edu.my</p>
  </form>

  <form id="form-register" hidden novalidate>
    <div class="form-group">
      <label class="form-label" for="rg-name">Nama Penuh</label>
      <input class="form-input" id="rg-name" name="full_name" type="text"
             placeholder="cth. Ahmad Faiz bin Razali" autocomplete="name" maxlength="100" required>
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label class="form-label" for="rg-type">Jenis Akaun</label>
        <select class="form-input" id="rg-type" name="user_type" aria-describedby="rg-type-hint">
          <option value="pelajar">Pelajar UTHM</option>
          <option value="staf">Staf UTHM</option>
        </select>
        <div class="form-hint" id="rg-type-hint">Kedua-duanya boleh membeli dan menjual.</div>
      </div>
      <div class="form-group">
        <label class="form-label" for="rg-role">Daftar Sebagai</label>
        <select class="form-input" id="rg-role" name="role" aria-describedby="rg-role-hint">
          <option value="pembeli">Pembeli</option>
          <option value="penjual">Penjual</option>
        </select>
        <div class="form-hint" id="rg-role-hint">Akaun penjual juga boleh membeli barang.</div>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label" for="rg-matrik" id="rg-ident-label">No. Matrik</label>
      <!-- Two inputs, never both visible: a student has a matric number and a
           staff member has a staff number, and each is stored in the column
           that describes it. hidden works here because .form-input sets no
           display rule, so nothing overrides the UA [hidden] style. -->
      <input class="form-input" id="rg-matrik" name="matric_no" type="text"
             placeholder="cth. DI240099" autocomplete="username" maxlength="20" required>
      <input class="form-input" id="rg-staff" name="staff_no" type="text"
             placeholder="cth. PTU-1234" autocomplete="off" maxlength="30" hidden required>
      <div class="form-hint" id="rg-ident-hint">Format: 2 huruf + 6–7 nombor, cth. DI240088.</div>
    </div>
    <div class="form-group">
      <label class="form-label" for="rg-faculty" id="rg-faculty-label">Fakulti</label>
      <select class="form-input" id="rg-faculty" name="faculty" required>
        <option value="">Pilih Fakulti</option>
        <option>FKEE</option><option>FKMP</option><option>FSKTM</option>
        <option>FKAAB</option><option>FPTP</option><option>FPTV</option>
        <!-- PTU is a centre, not a faculty: offered to staff accounts only. -->
        <option value="PTU" disabled>PTU</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label" for="rg-email">Emel UTHM</label>
      <input class="form-input" id="rg-email" name="email" type="email"
             placeholder="nama@student.uthm.edu.my" autocomplete="email" inputmode="email" maxlength="150" required>
      <div class="form-hint" id="rg-email-hint">Emel pelajar: nama@student.uthm.edu.my</div>
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label class="form-label" for="rg-pass">Kata Laluan</label>
        <input class="form-input" id="rg-pass" name="password" type="password"
               placeholder="Min. 8 aksara" autocomplete="new-password" minlength="8" required>
        <div class="password-meter" id="password-meter" data-score="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        <div class="password-hint" id="password-hint">Gunakan huruf dan nombor.</div>
      </div>
      <div class="form-group">
        <label class="form-label" for="rg-pass2">Sahkan Kata Laluan</label>
        <input class="form-input" id="rg-pass2" name="password2" type="password"
               placeholder="Ulang kata laluan" autocomplete="new-password" required>
      </div>
    </div>
    <button type="submit" class="btn-submit" id="reg-btn">Cipta Akaun <span aria-hidden="true">→</span></button>
    <p class="form-note">Dengan mendaftar, anda bersetuju untuk menggunakan TrustMart secara bertanggungjawab.</p>
  </form>
</main>

<div class="auth-modal" id="forgot-modal" aria-hidden="true">
  <div class="auth-modal-card" role="dialog" aria-modal="true" aria-labelledby="forgot-title" aria-describedby="forgot-description">
    <h2 class="auth-modal-title" id="forgot-title">Lupa Kata Laluan?</h2>
    <p class="auth-modal-sub" id="forgot-description">Masukkan emel UTHM anda untuk menjana pautan tetapan semula.</p>
    <form id="forgot-form">
      <div id="forgot-form-fields">
        <div class="form-group">
          <label class="form-label" for="forgot-email">Emel UTHM</label>
          <input class="form-input" type="email" id="forgot-email" name="email"
                 placeholder="nama@student.uthm.edu.my" autocomplete="email" inputmode="email" required>
        </div>
        <button type="submit" class="btn-submit" id="forgot-btn">Hantar Pautan <span aria-hidden="true">→</span></button>
      </div>
      <div id="forgot-result" role="status" aria-live="polite" hidden></div>
    </form>
    <div class="modal-actions"><button type="button" class="modal-close" id="forgot-close">Tutup</button></div>
  </div>
</div>

<script>
const ORIGINAL_DESTINATION = <?= json_encode($redirect_to, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const loginForm = document.getElementById('form-login');
const registerForm = document.getElementById('form-register');
const loginTab = document.getElementById('tab-login-btn');
const registerTab = document.getElementById('tab-reg-btn');
const forgotModal = document.getElementById('forgot-modal');

function switchTab(tab) {
  const isLogin = tab === 'login';
  loginForm.hidden = !isLogin;
  registerForm.hidden = isLogin;
  loginTab.classList.toggle('active', isLogin);
  registerTab.classList.toggle('active', !isLogin);
  loginTab.setAttribute('aria-selected', String(isLogin));
  registerTab.setAttribute('aria-selected', String(!isLogin));
  loginTab.tabIndex = isLogin ? 0 : -1;
  registerTab.tabIndex = isLogin ? -1 : 0;
  clearAlerts();
  (isLogin ? document.getElementById('li-email') : document.getElementById('rg-name')).focus();
}

function clearAlerts() {
  ['alert-error', 'alert-success'].forEach(id => {
    const element = document.getElementById(id);
    element.classList.remove('show');
    element.replaceChildren();
  });
}

function showError(message) {
  const element = document.getElementById('alert-error');
  element.textContent = message || 'Permintaan tidak berjaya. Sila cuba lagi.';
  element.classList.add('show');
  document.getElementById('alert-success').classList.remove('show');
  element.focus?.();
}

function showSuccess(message, link = null, linkLabel = '') {
  const element = document.getElementById('alert-success');
  element.replaceChildren(document.createTextNode(message));
  if (link) {
    const spacer = document.createElement('br');
    const anchor = document.createElement('a');
    anchor.href = link;
    anchor.textContent = linkLabel;
    anchor.style.display = 'inline-block';
    anchor.style.marginTop = '8px';
    element.append(spacer, anchor);
  }
  element.classList.add('show');
  document.getElementById('alert-error').classList.remove('show');
}

function localLink(value) {
  if (!value) return null;
  try {
    const url = new URL(value, location.href);
    return url.origin === location.origin ? url.href : null;
  } catch (_) {
    return null;
  }
}

async function parseResponse(response) {
  const type = response.headers.get('content-type') || '';
  if (!type.includes('application/json')) throw new Error('Respons pelayan tidak sah.');
  const data = await response.json();
  if (!response.ok && !data.message && !data.errors) throw new Error('Permintaan tidak berjaya.');
  return data;
}

function setBusy(button, busy, label) {
  button.disabled = busy;
  button.setAttribute('aria-busy', String(busy));
  button.innerHTML = busy ? '<span class="spinner" aria-hidden="true"></span>Memproses...' : label;
}

loginTab.addEventListener('click', () => switchTab('login'));
registerTab.addEventListener('click', () => switchTab('register'));

loginForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  clearAlerts();
  const email = document.getElementById('li-email').value.trim();
  const password = document.getElementById('li-pass').value;
  const button = document.getElementById('login-btn');

  if (!email || !password) {
    showError('Sila masukkan emel dan kata laluan.');
    return;
  }

  setBusy(button, true, 'Log Masuk <span aria-hidden="true">→</span>');
  try {
    const response = await fetch('../auth/login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: new URLSearchParams({ email, password }),
    });
    const data = await parseResponse(response);
    if (!data.success) {
      const attempts = data.remaining !== undefined && data.remaining <= 2 ? ` (${data.remaining} percubaan tinggal)` : '';
      showError((data.message || 'Log masuk tidak berjaya.') + attempts);
      return;
    }

    showSuccess(data.message || 'Log masuk berjaya.');
    const destination = localLink(ORIGINAL_DESTINATION) || localLink(data.redirect);
    window.setTimeout(() => { location.href = destination || 'index.php'; }, 550);
  } catch (error) {
    showError(error.message === 'Respons pelayan tidak sah.' ? error.message : 'Ralat rangkaian. Sila cuba lagi.');
  } finally {
    setBusy(button, false, 'Log Masuk <span aria-hidden="true">→</span>');
  }
});

// ── Jenis akaun: pelajar / staf ───────────────────────────────
// Swaps one visible identifier field, its label, the PTU faculty option and
// the email hint. The server enforces all of it; this only keeps the form from
// asking a staff member for a matric number.
const acctType   = document.getElementById('rg-type');
const matricIn   = document.getElementById('rg-matrik');
const staffIn    = document.getElementById('rg-staff');
const identLabel = document.getElementById('rg-ident-label');
const identHint  = document.getElementById('rg-ident-hint');
const facLabel   = document.getElementById('rg-faculty-label');
const facSelect  = document.getElementById('rg-faculty');
const facPtu     = facSelect.querySelector('option[value="PTU"]');
const emailIn    = document.getElementById('rg-email');
const emailHint  = document.getElementById('rg-email-hint');

function applyAccountType() {
  const isStaff = acctType.value === 'staf';
  matricIn.hidden = isStaff;
  staffIn.hidden  = !isStaff;
  // required must follow visibility: a hidden-but-required input is a
  // submission that can fail on a field the user cannot see or correct.
  matricIn.required = !isStaff;
  staffIn.required  = isStaff;
  identLabel.textContent = isStaff ? 'No. Pegawai' : 'No. Matrik';
  identLabel.htmlFor     = isStaff ? 'rg-staff' : 'rg-matrik';
  identHint.textContent  = isStaff
    ? 'No. pegawai seperti yang tertera pada kad staf UTHM.'
    : 'Format: 2 huruf + 6–7 nombor, cth. DI240088.';
  facLabel.textContent   = isStaff ? 'Fakulti / Pusat' : 'Fakulti';
  facPtu.disabled        = !isStaff;
  if (isStaff) {
    if (facSelect.value === '') facSelect.value = 'PTU';
    emailIn.placeholder = 'nama@uthm.edu.my';
    emailHint.textContent = 'Emel staf: nama@uthm.edu.my';
  } else {
    if (facSelect.value === 'PTU') facSelect.value = '';
    emailIn.placeholder = 'nama@student.uthm.edu.my';
    emailHint.textContent = 'Emel pelajar: nama@student.uthm.edu.my';
  }
}
acctType.addEventListener('change', applyAccountType);
applyAccountType();

registerForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  clearAlerts();
  const isStaff = acctType.value === 'staf';
  const ident   = document.getElementById(isStaff ? 'rg-staff' : 'rg-matrik').value.trim();
  const values = {
    full_name: document.getElementById('rg-name').value.trim(),
    user_type: acctType.value,
    role: document.getElementById('rg-role').value,
    faculty: facSelect.value,
    email: document.getElementById('rg-email').value.trim(),
    password: document.getElementById('rg-pass').value,
  };
  // Only the identifier this account type actually uses is sent, so the server
  // never receives a matric number and a staff number in one request.
  if (isStaff) { values.staff_no = ident; } else { values.matric_no = ident; }
  const confirmation = document.getElementById('rg-pass2').value;
  const button = document.getElementById('reg-btn');

  if (!values.full_name || !ident || !values.faculty || !values.email || !values.password) {
    showError('Sila isi semua maklumat yang diperlukan.');
    return;
  }
  if (isStaff && ident.length < 3) {
    showError('No. pegawai mestilah sekurang-kurangnya 3 aksara.');
    return;
  }
  if (values.password !== confirmation) {
    showError('Kata laluan tidak sepadan. Sila cuba semula.');
    return;
  }
  if (values.password.length < 8 || !/[A-Za-z]/.test(values.password) || !/[0-9]/.test(values.password)) {
    showError('Kata laluan mestilah sekurang-kurangnya 8 aksara serta mengandungi huruf dan nombor.');
    return;
  }

  setBusy(button, true, 'Cipta Akaun <span aria-hidden="true">→</span>');
  try {
    const response = await fetch('../auth/register.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: new URLSearchParams(values),
    });
    const data = await parseResponse(response);
    if (!data.success) {
      showError(data.errors ? data.errors.join(' ') : data.message);
      return;
    }

    const activationLink = localLink(data.activation_link);
    if (activationLink) {
      showSuccess('Akaun berjaya didaftarkan. Aktifkan akaun anda untuk meneruskan.', activationLink, 'Aktifkan Akaun Sekarang');
    } else {
      showSuccess(data.message || 'Akaun berjaya dicipta. Sila semak emel anda untuk mengaktifkan akaun.');
    }
  } catch (_) {
    showError('Ralat rangkaian. Sila cuba lagi.');
  } finally {
    setBusy(button, false, 'Cipta Akaun <span aria-hidden="true">→</span>');
  }
});

function setForgotOpen(open) {
  forgotModal.style.display = open ? 'flex' : 'none';
  forgotModal.setAttribute('aria-hidden', String(!open));
  if (open) {
    document.getElementById('forgot-email').value = document.getElementById('li-email').value;
    document.getElementById('forgot-form-fields').hidden = false;
    document.getElementById('forgot-result').hidden = true;
    window.setTimeout(() => document.getElementById('forgot-email').focus(), 30);
  } else {
    document.getElementById('forgot-open').focus();
  }
}

document.getElementById('forgot-open').addEventListener('click', () => setForgotOpen(true));
document.getElementById('forgot-close').addEventListener('click', () => setForgotOpen(false));
forgotModal.addEventListener('click', event => { if (event.target === forgotModal) setForgotOpen(false); });
document.addEventListener('keydown', event => { if (event.key === 'Escape' && forgotModal.style.display === 'flex') setForgotOpen(false); });

document.getElementById('forgot-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const email = document.getElementById('forgot-email').value.trim();
  const button = document.getElementById('forgot-btn');
  if (!email) return;

  setBusy(button, true, 'Hantar Pautan <span aria-hidden="true">→</span>');
  try {
    const response = await fetch('../auth/forgot_password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: new URLSearchParams({ email }),
    });
    const data = await parseResponse(response);
    const result = document.getElementById('forgot-result');
    result.replaceChildren(document.createTextNode(data.message || 'Jika akaun wujud, pautan tetapan semula telah dijana.'));
    const resetLink = localLink(data.reset_link);
    if (resetLink) {
      const spacer = document.createElement('br');
      const anchor = document.createElement('a');
      anchor.href = resetLink;
      anchor.textContent = 'Tetapkan Semula Kata Laluan';
      anchor.style.display = 'inline-block';
      anchor.style.marginTop = '8px';
      anchor.style.color = 'var(--gold)';
      anchor.style.fontWeight = '700';
      result.append(spacer, anchor);
    }
    result.hidden = false;
    document.getElementById('forgot-form-fields').hidden = true;
  } catch (_) {
    showError('Ralat rangkaian. Sila cuba lagi.');
    setForgotOpen(false);
  } finally {
    setBusy(button, false, 'Hantar Pautan <span aria-hidden="true">→</span>');
  }
});

const passwordInput = document.getElementById('rg-pass');
passwordInput.addEventListener('input', () => {
  const value = passwordInput.value;
  let score = 0;
  if (value.length >= 8) score++;
  if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score++;
  if (/\d/.test(value)) score++;
  if (/[^A-Za-z0-9]/.test(value) && value.length >= 10) score++;
  document.getElementById('password-meter').dataset.score = String(score);
  document.getElementById('password-hint').textContent = [
    'Gunakan sekurang-kurangnya 8 aksara.',
    'Tambah nombor untuk kata laluan lebih kuat.',
    'Tambah huruf besar dan kecil.',
    'Baik — tambah simbol untuk perlindungan tambahan.',
    'Kata laluan kuat.'
  ][score];
});

const query = new URLSearchParams(location.search);
if (query.get('tab') === 'register') switchTab('register');
if (query.get('msg') === 'logout') showSuccess('Anda telah log keluar dengan selamat.');
if (query.get('msg') === 'session') showError('Sesi anda telah tamat. Sila log masuk semula.');
</script>
</body>
</html>
