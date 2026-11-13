<?php
// ============================================================
// includes/header.php
// Shared HTML head, fonts, CSS variables, and topbar.
// Usage: require_once at the top of every dashboard page
//        AFTER auth_check.php and role check.
//
// Variables expected from the parent file:
//   $page_title  — string shown in <title>
//   $user        — current_user() array
// ============================================================

$page_title ??= 'TrustMart';

// ── Auto-detect the relative asset base for the CALLING script ──
// header.php may be included from files at different depths
// (/product.php at root, /dashboard/*.php and /payment/*.php one level
//  in, /auth/*.php one level in). Emitting a plain relative
// "public/assets/..." only resolves correctly from the app root, so
// the CSS/JS 404 on every non-root includer. Compute the "../" prefix
// from the calling script's filesystem directory back to the app root.
$__hdr_dir    = realpath(__DIR__);
$__app_root   = str_replace('\\', '/', $__hdr_dir ? dirname($__hdr_dir) : dirname(str_replace('\\', '/', __DIR__)));   // .../Trustmart (physical)
$__sf         = $_SERVER['SCRIPT_FILENAME'] ?? '';
$__script_dir = '';
if ($__sf !== '') {
    $__real = realpath(dirname($__sf));
    $__script_dir = str_replace('\\', '/', $__real !== false ? $__real : dirname($__sf));
}
$__asset_base = '';
// A web root that is a symlink/junction (our case) makes __DIR__ and
// SCRIPT_FILENAME report two DIFFERENT path strings for the same folder, so a
// raw prefix compare failed and left $__asset_base empty — which 404s the CSS/JS
// on every page one level deep (payment/, auth/). realpath() collapses both to
// the same physical root first; stripos() also covers Windows drive-letter case.
if ($__script_dir !== '' && stripos($__script_dir, $__app_root . '/') === 0) {
    $__rel     = substr($__script_dir, strlen($__app_root) + 1);
    $__depth   = ($__rel === '') ? 0 : (substr_count($__rel, '/') + 1);
    $__asset_base = str_repeat('../', $__depth);
}
unset($__hdr_dir, $__app_root, $__sf, $__script_dir, $__real, $__rel, $__depth);
?>
<!DOCTYPE html>
<html lang="ms">
<head>
  <meta charset="UTF-8">
  <?= csrf_meta_tag() ?>
  <?= csrf_script() ?>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($page_title) ?> — TrustMart</title>
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
      --r:10px;--r-sm:6px;--r-lg:16px;
      --shadow:0 2px 16px rgba(26,23,20,.08);
      --shadow-sm:0 1px 4px rgba(26,23,20,.06);
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);
         color:var(--ink);font-size:14px}
    .shell{display:flex;height:100vh;overflow:hidden}
    .sidebar{width:220px;background:var(--ink);flex-shrink:0;
             display:flex;flex-direction:column;padding:24px 0}
    .main{flex:1;overflow-y:auto;background:var(--cream)}
    .topbar{height:60px;background:#fff;border-bottom:1px solid var(--cream3);
            display:flex;align-items:center;padding:0 28px;gap:16px;
            position:sticky;top:0;z-index:10}
    .content{padding:28px}
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
    .nav-item svg{opacity:.6;flex-shrink:0}
    .nav-item.active svg{opacity:1;stroke:var(--gold)}
    .sidebar-footer{margin-top:auto;padding:16px 20px;
                    border-top:1px solid rgba(255,255,255,.08)}
    .avatar{width:32px;height:32px;border-radius:50%;background:var(--gold);
            display:flex;align-items:center;justify-content:center;
            font-size:12px;font-weight:600;color:var(--ink);flex-shrink:0}
    .stat-card{background:#fff;border:1px solid var(--cream3);
               border-radius:var(--r);padding:18px;position:relative;overflow:hidden}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
    .stat-card.gold::before{background:var(--gold)}
    .stat-card.green::before{background:var(--green)}
    .stat-card.blue::before{background:var(--blue)}
    .stat-card.red::before{background:var(--red)}
    .stat-label{font-size:11px;color:var(--ink3);letter-spacing:.5px;
                text-transform:uppercase;margin-bottom:8px}
    .stat-val{font-size:26px;font-weight:600;line-height:1;margin-bottom:4px}
    .stat-sub{font-size:11px;color:var(--ink4)}
    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px}
    .tbl-wrap{background:#fff;border:1px solid var(--cream3);
              border-radius:var(--r);overflow:hidden;margin-bottom:20px}
    .tbl{width:100%;border-collapse:collapse}
    .tbl th{background:var(--cream2);text-align:left;padding:10px 16px;
            font-size:11px;font-weight:500;letter-spacing:.5px;text-transform:uppercase;
            color:var(--ink3);border-bottom:1px solid var(--cream3)}
    .tbl td{padding:12px 16px;font-size:13px;border-bottom:1px solid var(--cream2)}
    .tbl tr:last-child td{border-bottom:none}
    .tbl tr:hover td{background:var(--cream)}
    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;
           border-radius:20px;font-size:11px;font-weight:500}
    .badge-dot{width:5px;height:5px;border-radius:50%;background:currentColor}
    .badge-gold{background:var(--gold-pale);color:#7A5A10}
    .badge-blue{background:var(--blue-bg);color:var(--blue)}
    .badge-green{background:var(--green-bg);color:var(--green)}
    .badge-red{background:var(--red-bg);color:var(--red)}
    .badge-gray{background:var(--cream2);color:var(--ink3)}
    .btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;
         border-radius:var(--r-sm);border:1px solid var(--cream3);background:#fff;
         color:var(--ink);font-size:12px;font-weight:500;cursor:pointer;
         transition:.15s;font-family:inherit;text-decoration:none}
    .btn:hover{border-color:var(--gold);color:var(--gold)}
    .btn-primary{background:var(--gold);border-color:var(--gold);color:var(--ink)}
    .btn-primary:hover{background:#b8942f;border-color:#b8942f}
    .btn-danger{background:var(--red-bg);border-color:var(--red);color:var(--red)}
    .btn-success{background:var(--green-bg);border-color:var(--green);color:var(--green)}
    .btn-sm{padding:5px 10px;font-size:11px}
    .btn:disabled{opacity:.5;cursor:not-allowed}
    .section-hd{display:flex;align-items:center;justify-content:space-between;
                margin-bottom:16px}
    .section-title{font-size:15px;font-weight:500}
    .card-box{background:#fff;border:1px solid var(--cream3);
              border-radius:var(--r);padding:20px}
    .filters{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
    .filter-btn{padding:5px 12px;border-radius:20px;border:1px solid var(--cream3);
                background:#fff;color:var(--ink3);font-size:12px;cursor:pointer;
                transition:.15s;font-family:inherit}
    .filter-btn.active{border-color:var(--gold);color:var(--ink);background:var(--gold-pale)}
    .two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px}
    .three-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
    .divider{height:1px;background:var(--cream2);margin:16px 0}
    .empty-state{text-align:center;padding:32px;color:var(--ink3)}
    .spinner{display:inline-block;width:13px;height:13px;
             border:2px solid rgba(0,0,0,.15);border-top-color:var(--ink);
             border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle}
    @keyframes spin{to{transform:rotate(360deg)}}
    .toast{position:fixed;bottom:24px;right:24px;color:#fff;padding:12px 18px;
           border-radius:var(--r);font-size:13px;z-index:200;max-width:320px;
           transform:translateY(100px);opacity:0;transition:all .3s;pointer-events:none}
    .toast.show{transform:translateY(0);opacity:1}
    .toast.success{background:var(--green)}.toast.error{background:var(--red)}
    .toast.info{background:var(--ink)}
    .modal-bg{position:fixed;inset:0;background:rgba(26,23,20,.5);z-index:100;
              display:flex;align-items:center;justify-content:center}
    .modal{background:#fff;border-radius:var(--r-lg);padding:28px;width:520px;
           max-width:90vw;max-height:85vh;overflow-y:auto}
    .modal-title{font-family:'DM Serif Display',serif;font-size:20px;margin-bottom:4px}
    .modal-sub{font-size:12px;color:var(--ink3);margin-bottom:20px}
    .modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:20px;
                  padding-top:16px;border-top:1px solid var(--cream2)}
    .form-group{margin-bottom:14px}
    .form-label{display:block;font-size:12px;font-weight:500;margin-bottom:5px}
    .form-input{width:100%;padding:10px 14px;border:1px solid var(--cream3);
                border-radius:var(--r-sm);font-size:13px;font-family:inherit;
                outline:none;background:#fff;color:var(--ink)}
    .form-input:focus{border-color:var(--gold)}
    textarea.form-input{resize:vertical;min-height:80px}
    @media(max-width:768px){
      .sidebar{display:none}
      .stats-grid{grid-template-columns:1fr 1fr}
      .two-col,.three-col{grid-template-columns:1fr}
    }
  </style>
  <meta name="theme-color" content="#171b26">
  <link rel="stylesheet" href="<?= $__asset_base ?>public/assets/css/trustmart-ui.css">
  <script src="<?= $__asset_base ?>public/assets/js/trustmart-ui.js" defer></script>
</head>
<body>
<div class="shell">

  <!-- Sidebar built per role in each dashboard file -->