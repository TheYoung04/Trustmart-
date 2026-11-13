<?php
// ============================================================
// tests/uat/lib/uat_lib.php
// Shared UAT harness for TrustMart. Reuses the repository's
// existing PHP-CLI + curl/cookie-jar test convention (see
// scratch/test_e2e_*.php) because the project has no PHPUnit /
// Playwright / composer install.
//
// Provides: multi-session (login-as-role) HTTP client that is
// CSRF-aware and can request in JSON mode (so guards return
// 401/403 JSON instead of a redirect), an assertion recorder,
// direct DB access for setup/teardown, and a cleanup registry.
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../../config/db.php'; // Trustmart/config/db.php

$GLOBALS['UAT_BASE']   = 'http://localhost:8080';
$GLOBALS['UAT_PASS']   = 0;
$GLOBALS['UAT_FAIL']   = 0;
$GLOBALS['UAT_RESULTS']= [];   // [ [id, name, ok, detail], ... ]
$GLOBALS['UAT_CLEANUP']= [];   // callables run LIFO at shutdown
$GLOBALS['UAT_JARS']   = [];   // temp cookie jars to delete

function uat_set_base(?string $url): void { if ($url) $GLOBALS['UAT_BASE'] = rtrim($url, '/'); }
function uat_base(): string { return $GLOBALS['UAT_BASE']; }
function uat_db(): PDO { global $pdo; return $pdo; }

/** A logged-in browser session (own cookie jar + cached CSRF token). */
class UatSession {
    public string $jar;
    public string $token = '';
    public ?int $uid = null;
    public string $role = '';
    public function __construct() {
        $this->jar = tempnam(sys_get_temp_dir(), 'uatjar_');
        $GLOBALS['UAT_JARS'][] = $this->jar;
    }
}

/**
 * Low-level request. $opts:
 *   post   => array  (form fields; implies POST)
 *   json   => bool   (send Accept: application/json so guards return JSON)
 *   hdr    => string[] (extra headers)
 *   follow => bool   (default true)
 * Returns [int status, string body, ?array json].
 */
function uat_req(UatSession $s, string $path, array $opts = []): array {
    $url = str_starts_with($path, 'http') ? $path : uat_base() . $path;
    $ch  = curl_init($url);
    $hdr = $opts['hdr'] ?? [];
    if (!empty($opts['json'])) $hdr[] = 'Accept: application/json';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $opts['follow'] ?? true,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_COOKIEJAR      => $s->jar,
        CURLOPT_COOKIEFILE     => $s->jar,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => $hdr,
    ]);
    if (!empty($opts['post'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['post']));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = (string)$body;
    $json = json_decode($body, true);
    return [$code, $body, is_array($json) ? $json : null];
}

/** Extract the CSRF token from a page's <meta name="csrf-token"> and cache it. */
function uat_token(UatSession $s, string $path = '/index.php'): string {
    [, $body] = uat_req($s, $path);
    if (preg_match('/name="csrf-token"\s+content="([^"]+)"/', $body, $m)) {
        $s->token = $m[1];
    }
    return $s->token;
}

/** Log in and return a session. Populates ->uid / ->role from the DB. */
function uat_login(string $email, string $password = 'password123'): UatSession {
    $s = new UatSession();
    uat_token($s, '/index.php');
    uat_req($s, '/auth/login.php', [
        'post'  => ['email' => $email, 'password' => $password, '_csrf_token' => $s->token],
        'json'  => true,
    ]);
    $row = uat_db()->prepare("SELECT user_id, role FROM users WHERE email = ?");
    $row->execute([$email]);
    if ($u = $row->fetch(PDO::FETCH_ASSOC)) { $s->uid = (int)$u['user_id']; $s->role = $u['role']; }
    return $s;
}

/** Record an assertion. */
function uat_check(string $id, string $name, bool $cond, string $detail = ''): bool {
    $GLOBALS['UAT_RESULTS'][] = compact('id', 'name', 'cond') + ['ok' => $cond, 'detail' => $detail];
    if ($cond) { $GLOBALS['UAT_PASS']++; echo "  PASS  [$id] $name\n"; }
    else       { $GLOBALS['UAT_FAIL']++; echo "  FAIL  [$id] $name  -- $detail\n"; }
    return $cond;
}

/** Register a teardown callable (runs LIFO at shutdown). */
function uat_on_cleanup(callable $fn): void { $GLOBALS['UAT_CLEANUP'][] = $fn; }

/** Print the role summary and run cleanup. Returns process exit code. */
function uat_finish(string $role): int {
    $p = $GLOBALS['UAT_PASS']; $f = $GLOBALS['UAT_FAIL'];
    echo "\n==== UAT [$role] RESULT: $p PASS / $f FAIL ====\n";
    return $f === 0 ? 0 : 1;
}

function uat_shutdown(): void {
    foreach (array_reverse($GLOBALS['UAT_CLEANUP']) as $fn) {
        try { $fn(); } catch (Throwable $e) { echo "[cleanup-warn] {$e->getMessage()}\n"; }
    }
    foreach ($GLOBALS['UAT_JARS'] as $j) { @unlink($j); }
}
register_shutdown_function('uat_shutdown');

/** Demo account emails by role (from the seeded DB). */
function uat_demo(string $role): array {
    $stmt = uat_db()->prepare("SELECT email FROM users WHERE role = ? AND is_active = 1 ORDER BY user_id ASC LIMIT 3");
    $stmt->execute([$role]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}
