<?php
/**
 * HTTP smoke test: the pages modified for the AI Trust Layer UI must render
 * (200, no fatal) and contain the new blocks; admin's get_ai_risks must
 * return valid JSON. Requires Apache running at http://localhost/Trustmart.
 * Run: php scratch/test_ai_ui_smoke.php
 */

$base = 'http://localhost/Trustmart';
$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail) { echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . "\n"; $c ? $pass++ : $fail++; }

function http($url, $post = null, $cookieFile = null, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$body];
}

function login($base, $email, $pwFile) {
    @unlink($pwFile);
    // The landing page (public/index.php) carries the CSRF token (meta tag).
    [$c, $page] = http("$base/public/", null, $pwFile);
    $tok = '';
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m)) $tok = $m[1];
    elseif (preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m)) $tok = $m[1];
    http("$base/auth/login.php",
        ['email' => $email, 'password' => 'password123', '_csrf_token' => $tok],
        $pwFile, ['X-CSRF-Token: ' . $tok, 'Accept: application/json']);
    return $pwFile;
}

$tmp = sys_get_temp_dir();

// ── Admin ────────────────────────────────────────────────────
echo "== Admin ==\n";
$c = login($base, 'admin@uthm.edu.my', "$tmp/tm_admin.txt");
[$code, $body] = http("$base/dashboard/admin.php", null, $c);
check("admin.php renders (200, no fatal)", $code === 200 && strpos($body, 'Fatal error') === false, $pass, $fail);
check("AI risk review panel present", strpos($body, 'ai-risk-panel') !== false, $pass, $fail);
[$code, $body] = http("$base/dashboard/admin.php?action=get_ai_risks", null, $c);
$json = json_decode($body, true);
check("get_ai_risks returns JSON", $code === 200 && is_array($json) && ($json['success'] ?? false), $pass, $fail);
check("get_ai_risks carries hold_risk + rows", isset($json['hold_risk'], $json['rows']), $pass, $fail);

// ── Buyer ────────────────────────────────────────────────────
echo "\n== Buyer ==\n";
$c = login($base, 'aishah@student.uthm.edu.my', "$tmp/tm_buyer.txt");
[$code, $body] = http("$base/dashboard/buyer.php", null, $c);
check("buyer.php renders (200, no fatal)", $code === 200 && strpos($body, 'Fatal error') === false, $pass, $fail);
check("catalogue aiBadge() present", strpos($body, 'function aiBadge') !== false, $pass, $fail);
$pid = (int)($argv[1] ?? 1);
[$code, $body] = http("$base/product.php?id=$pid", null, $c);
check("product.php renders (200) for id=$pid", $code === 200 && strpos($body, 'Fatal error') === false, $pass, $fail);
$hasAi = (strpos($body, 'Lapisan AI') !== false || strpos($body, 'ai_risk') !== false || strpos($body, '🛡') !== false);
check("product.php shows AI layer content or badge", $hasAi, $pass, $fail);

// ── Seller ───────────────────────────────────────────────────
echo "\n== Seller ==\n";
$c = login($base, 'haziq@student.uthm.edu.my', "$tmp/tm_seller.txt");
[$code, $body] = http("$base/dashboard/seller.php", null, $c);
check("seller.php renders (200, no fatal)", $code === 200 && strpos($body, 'Fatal error') === false, $pass, $fail);
check("seller trust modal intact", strpos($body, 'seller-trust-modal') !== false, $pass, $fail);

echo "\n==================================\nRESULT: $pass passed, $fail failed\n==================================\n";
exit($fail === 0 ? 0 : 1);
