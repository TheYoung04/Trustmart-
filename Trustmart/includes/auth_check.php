<?php
// ============================================================
// includes/auth_check.php
// Central auth guard — required at the top of every
// protected page before any HTML is output.
//
// Usage on any protected page:
//   require_once __DIR__ . '/../includes/auth_check.php';
//   require_role('pembeli');
//   require_role(['penjual', 'pentadbir']);
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── CSRF protection ──────────────────────────────────────────
require_once __DIR__ . '/csrf.php';

// Baseline browser protections that are safe with the application's current
// inline styles/scripts. A strict CSP can be added after those are extracted.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

// ── Session shape shim ────────────────────────────────────────
// Sessions opened before staff accounts existed carry matric_no but no
// ident_no / user_type. Backfilling once here lets every protected page print
// $user['ident_no'] unconditionally, instead of repeating a fallback at each of
// the places an identifier is shown. For an existing student account the
// backfilled value is exactly the string it already had, and the real columns
// arrive on the next login.
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $_SESSION['user']['user_type'] ??= 'pelajar';
    $_SESSION['user']['staff_no']  ??= null;
    if (!isset($_SESSION['user']['ident_no'])) {
        $_SESSION['user']['ident_no'] = (string) ($_SESSION['user']['matric_no'] ?? '');
    }
}

// ── is_logged_in() ────────────────────────────────────────────
function is_logged_in(): bool
{
    return isset($_SESSION['user']['user_id'])
        && is_int($_SESSION['user']['user_id'])
        && $_SESSION['user']['user_id'] > 0;
}

// ── current_user() ────────────────────────────────────────────
// Returns the full session user array, or null.
function current_user(): ?array
{
    return is_logged_in() ? $_SESSION['user'] : null;
}

// ── require_role() ────────────────────────────────────────────
// Stops execution if the visitor is not logged in or
// does not hold one of the allowed roles.
// For API endpoints (fetch calls) it returns JSON.
// For page requests it redirects to the login page.
function require_role(string|array $allowed): void
{
    $allowed   = (array) $allowed;
    $is_api    = (
        isset($_SERVER['HTTP_ACCEPT']) &&
        str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')
    ) || (
        isset($_SERVER['CONTENT_TYPE']) &&
        str_contains($_SERVER['CONTENT_TYPE'], 'application/json')
    );

    // Relative path back to the project root. Used by the login redirect and
    // by the "wrong role" page's home link, so compute it for both cases.
    // Normalise to forward slashes AND compare case-insensitively: on Windows
    // realpath() can return different drive-letter casing for the two paths
    // (e.g. c:\... vs C:\...), which used to break the prefix calculation and
    // send unauthenticated visitors to a 404.
    $project_root   = str_replace('\\', '/', strtolower((string) realpath(__DIR__ . '/..')));
    $current_script = str_replace('\\', '/', strtolower((string) realpath($_SERVER['SCRIPT_FILENAME'] ?? '')));
    $rel_path = (strpos($current_script, $project_root) === 0)
        ? substr($current_script, strlen($project_root))
        : '/' . basename($current_script);
    $depth    = max(0, substr_count(ltrim($rel_path, '/'), '/'));
    $prefix   = str_repeat('../', $depth);

    if (!is_logged_in()) {
        if ($is_api) {
            http_response_code(401);
            header('Content-Type: application/json');
            die(json_encode([
                'success' => false,
                'message' => 'Sesi tamat. Sila log masuk semula.',
                'redirect'=> $prefix . 'public/index.php',
            ]));
        }
        $return = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header("Location: " . $prefix . "public/index.php?redirect={$return}");
        exit;
    }

    $role = $_SESSION['user']['role'];

    if (!in_array($role, $allowed, true)) {
        if ($is_api) {
            http_response_code(403);
            header('Content-Type: application/json');
            die(json_encode([
                'success' => false,
                'message' => 'Akses tidak dibenarkan untuk peranan anda.',
            ]));
        }
        http_response_code(403);
        // Render a minimal, styled 403 page rather than a blank screen
        $name = htmlspecialchars($_SESSION['user']['full_name']);
        die(<<<HTML
        <!DOCTYPE html>
        <html lang="ms">
        <head>
          <meta charset="UTF-8">
          <title>403 — Akses Ditolak</title>
          <style>
            body { font-family: sans-serif; display: flex; align-items: center;
                   justify-content: center; min-height: 100vh; margin: 0;
                   background: #FBF8F2; color: #1A1714; }
            .box { text-align: center; max-width: 400px; padding: 40px; }
            h1   { font-size: 48px; margin: 0 0 8px; color: #C9A84C; }
            p    { color: #6B6358; margin-bottom: 24px; }
            a    { display: inline-block; padding: 10px 24px; background: #1A1714;
                   color: #fff; border-radius: 8px; text-decoration: none; }
          </style>
        </head>
        <body>
          <div class="box">
            <h1>403</h1>
            <p>Hai {$name}, halaman ini bukan untuk peranan anda.</p>
            <a href="{$prefix}public/index.php">Kembali ke utama</a>
          </div>
        </body>
        </html>
        HTML);
    }
}

// ── refresh_session_user() ────────────────────────────────────
// Call this after any operation that changes the user's own row
// (e.g. trust score update, verification change) so the session
// stays in sync without forcing a re-login.
function refresh_session_user(PDO $pdo): void
{
    if (!is_logged_in()) return;

    $stmt = $pdo->prepare(
        'SELECT user_id, full_name, matric_no, staff_no, user_type,
                COALESCE(NULLIF(matric_no, \'\'), staff_no) AS ident_no,
                email,
                role, faculty, is_verified, trust_score,
                verification_status, is_active
           FROM users
          WHERE user_id = ?'
    );
    $stmt->execute([$_SESSION['user']['user_id']]);
    $fresh = $stmt->fetch();

    // A deleted or suspended account must lose access immediately, not only
    // after its next login attempt.
    if (!$fresh
        || !(bool) $fresh['is_active']
        || !in_array($fresh['role'], ['pembeli', 'penjual', 'pentadbir'], true)) {
        unset($_SESSION['user']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        return;
    }

    $_SESSION['user'] = [
        'user_id'             => (int)   $fresh['user_id'],
        'full_name'           =>         $fresh['full_name'],
        'matric_no'           =>         $fresh['matric_no'],
        'staff_no'            =>         $fresh['staff_no'],
        'user_type'           =>         $fresh['user_type'],
        'ident_no'            =>         $fresh['ident_no'],
        'email'               =>         $fresh['email'],
        'role'                =>         $fresh['role'],
        'faculty'             =>         $fresh['faculty'],
        'is_verified'         => (bool)  $fresh['is_verified'],
        'trust_score'         => (float) $fresh['trust_score'],
        'verification_status' =>         $fresh['verification_status'],
        'is_active'           => true,
    ];
}