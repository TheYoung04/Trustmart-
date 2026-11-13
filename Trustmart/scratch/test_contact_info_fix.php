<?php
// ============================================================
// test_contact_info_fix.php
// Verifies the two UI-text fixes:
//   1. buyer chat no longer injects fake trust_score:100 /
//      is_verified:1 — chat_api get_contact_info returns the
//      seller's REAL values instead.
//   2. register.php / forgot_password.php no longer ship the
//      dead _dev_notice string in their JSON responses.
// Usage: php test_contact_info_fix.php [base_url]
// ============================================================
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail, $d = '') {
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}
$jar = sys_get_temp_dir() . '/tm_contact_fix.txt';
@unlink($jar);
function http($url, $post = null, $hdr = [], $useJar = true) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($useJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$body];
}
function token_from($body) {
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m)) return $m[1];
    if (preg_match('/name="_csrf_token" value="([^"]+)"/', $body, $m)) return $m[1];
    return '';
}

echo "Target: $base\n\n";

// ── 1. Source no longer contains the fake placeholder values ────────────
echo "-- buyer.php mock removal (static) --\n";
$buyerSrc = file_get_contents($root . '/dashboard/buyer.php');
check('buyer.php no longer hardcodes trust_score: 100', strpos($buyerSrc, 'trust_score: 100') === false, $pass, $fail);
check('buyer.php no longer fakes is_verified: 1 for new chats', strpos($buyerSrc, "is_verified: 1, // assume verified") === false, $pass, $fail);
check('buyer.php calls the real get_contact_info endpoint', strpos($buyerSrc, 'action=get_contact_info') !== false, $pass, $fail);
// The DOMContentLoaded init once auto-populated the header with (100, 1).
check('buyer.php has no selectChatContact(..., 100, 1) hardcode', !preg_match('/selectChatContact\([^)]*100,\s*1\)/', $buyerSrc), $pass, $fail);

echo "\n-- _dev_notice removal (static) --\n";
check('register.php no longer ships _dev_notice', strpos(file_get_contents($root . '/auth/register.php'), '_dev_notice') === false, $pass, $fail);
check('forgot_password.php no longer ships _dev_notice', strpos(file_get_contents($root . '/auth/forgot_password.php'), '_dev_notice') === false, $pass, $fail);

// ── 2. Endpoint requires authentication ─────────────────────────────────
echo "\n-- chat_api get_contact_info (HTTP) --\n";
[$code, $body] = http("$base/chat_api.php?action=get_contact_info&user_id=2", null, [], false);
check('unauthenticated request is rejected (401)', $code === 401, $pass, $fail, "http $code");

// ── 3. Login as a buyer and fetch a real seller's profile ───────────────
require_once $root . '/includes/trust_score.php';
$seller = $pdo->query(
    "SELECT u.user_id, u.full_name, u.role, u.is_verified, u.verification_status,
            tp.score AS trust_baseline, tp.evidence_units AS trust_evidence, tp.ai_trust_score
       FROM users u
       LEFT JOIN trust_profiles tp ON tp.user_id = u.user_id AND tp.profile_role = u.role
      WHERE u.role='penjual' AND u.is_active=1 LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (!$seller) { echo "No active seller in DB — aborting live checks.\n"; exit(1); }
// The single source-of-truth label the API is expected to reproduce.
$expected_display = trust_display_from_row($seller);
$buyerEmail = $pdo->query(
    "SELECT email FROM users WHERE role='pembeli' AND is_active=1 AND user_id <> {$seller['user_id']} LIMIT 1"
)->fetchColumn();

[, $page] = http("$base/public/");
$tok = token_from($page);
[$c, $loginBody] = http("$base/auth/login.php",
    ['email' => $buyerEmail, 'password' => 'password123', '_csrf_token' => $tok],
    ['X-CSRF-Token: ' . $tok, 'Accept: application/json']);
echo "logged in as $buyerEmail: " . (strpos($loginBody, '"success":true') !== false ? 'ok' : "FAILED ($loginBody)") . "\n";

[$code, $body] = http("$base/chat_api.php?action=get_contact_info&user_id={$seller['user_id']}");
$data = json_decode($body, true);
check('authenticated request succeeds (200 + success flag)', $code === 200 && ($data['success'] ?? false), $pass, $fail, "http $code");

$contact = $data['contact'] ?? [];
check('returns the seller REAL full_name', ($contact['full_name'] ?? '') === $seller['full_name'], $pass, $fail, ($contact['full_name'] ?? 'missing'));
check('returns the seller REAL is_verified', (int)($contact['is_verified'] ?? -1) === (int)$seller['is_verified'], $pass, $fail, 'db=' . $seller['is_verified'] . ' api=' . ($contact['is_verified'] ?? 'missing'));
check('trust_display matches the trust_profiles source (not legacy users mirror)',
    ($contact['trust_display'] ?? null) === $expected_display['label'], $pass, $fail,
    'expected=' . $expected_display['label'] . ' api=' . ($contact['trust_display'] ?? 'missing'));
check('no-evidence seller shows "Baharu" in chat (parity with product page)',
    (float)($seller['trust_evidence'] ?? 0) > 0 || ($contact['trust_display'] ?? '') === 'Baharu', $pass, $fail,
    'evidence=' . ($seller['trust_evidence'] ?? '?') . ' display=' . ($contact['trust_display'] ?? '?'));
check('payload is NOT the canned placeholder (100/verified)',
    ($contact['trust_display'] ?? '') !== '100', $pass, $fail);

// ── 4. Bad input handling ───────────────────────────────────────────────
[$code, $body] = http("$base/chat_api.php?action=get_contact_info");
check('missing user_id returns 422', $code === 422, $pass, $fail, "http $code");
[$code, $body] = http("$base/chat_api.php?action=get_contact_info&user_id=999999");
check('unknown user_id returns 404', $code === 404, $pass, $fail, "http $code");

// ── 5. Response shape carries the unified trust fields ──────────────────
$missing = array_diff(['user_id','full_name','role','is_verified','verification_status',
                       'trust_display','trust_has_score','trust_display_color'], array_keys($contact));
check('contact payload exposes the unified profile fields', empty($missing), $pass, $fail, implode(',', $missing));
check('legacy users.trust_score is NO LONGER leaked', !array_key_exists('trust_score', $contact), $pass, $fail);

echo "\nRESULT: $pass passed, $fail failed\n";
@unlink($jar);
exit($fail === 0 ? 0 : 1);
