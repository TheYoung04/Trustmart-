<?php
// ============================================================
// test_card_score_merge.php
// Regression test for the "one number per card" change:
//   - ai_trust_badge_html() renders NOTHING while the AI agrees
//     with the baseline (no penalty, low risk).
//   - A penalty shows as a delta badge (🛡 AI −N).
//   - High risk without penalty still warns (🛡 AI ⚠).
//   - search_products payload carries ai_adjustment.
// Usage: php test_card_score_merge.php [base_url]
// ============================================================
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/includes/ai_trust.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail, $d = '') {
    echo ($c ? "  [PASS] " : "  [FAIL] ") . $n . ($d ? "  ($d)" : "") . "\n";
    $c ? $pass++ : $fail++;
}

echo "-- unit: divergence rule --\n";
check('clean seller (adj 0, risk 0)  -> no badge',
    ai_trust_badge_html(['ai_trust_score' => 40, 'ai_adjustment' => 0, 'fraud_risk' => 0]) === '', $pass, $fail);
check('AI not yet computed (null)     -> no badge',
    ai_trust_badge_html(['ai_trust_score' => null, 'ai_adjustment' => 0, 'fraud_risk' => 0]) === '', $pass, $fail);
check('borderline risk 24, no penalty -> no badge',
    ai_trust_badge_html(['ai_trust_score' => 40, 'ai_adjustment' => 0, 'fraud_risk' => 24]) === '', $pass, $fail);
$pen = ai_trust_badge_html(['ai_trust_score' => 21, 'ai_adjustment' => -19, 'fraud_risk' => 60]);
check('penalised seller               -> badge shown', $pen !== '', $pass, $fail);
check('penalty badge shows the DELTA not a duplicate score',
    strpos($pen, '&minus;19') !== false && strpos($pen, '21') === false, $pass, $fail, substr($pen, 0, 80));
$warn = ai_trust_badge_html(['ai_trust_score' => 40, 'ai_adjustment' => 0, 'fraud_risk' => 30]);
check('risk w/o penalty               -> warning badge', $warn !== '' && strpos($warn, '&#9888;') !== false, $pass, $fail);
check('ai_trust_diverged() agrees with badge', ai_trust_diverged(['ai_trust_score' => 40, 'ai_adjustment' => -1, 'fraud_risk' => 0])
    && !ai_trust_diverged(['ai_trust_score' => 40, 'ai_adjustment' => 0, 'fraud_risk' => 10]), $pass, $fail);

echo "\n-- static: card renderers use the merged rule --\n";
$buyerSrc = file_get_contents($root . '/dashboard/buyer.php');
check('buyer PHP card renders the unified trust_display', strpos($buyerSrc, "Trust Score: <?= htmlspecialchars(\$p['trust_display']) ?>") !== false, $pass, $fail);
check('buyer JS has effectiveScore() helper', strpos($buyerSrc, 'function effectiveScore(p)') !== false, $pass, $fail);
check('buyer JS badge hides when AI matches baseline',
    strpos($buyerSrc, 'if (adj >= 0 && risk < 25)') !== false, $pass, $fail);
check('both buyer card queries select ai_adjustment',
    substr_count($buyerSrc, 'tp.ai_trust_score, tp.ai_adjustment, tp.fraud_risk') === 2, $pass, $fail);

echo "\n-- live: search_products payload --\n";
$jar = sys_get_temp_dir() . '/tm_card_merge.txt';
@unlink($jar);
function http($url, $post = null, $hdr = []) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20,
    ]);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$body];
}
function token_from($body) {
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m)) return $m[1];
    return '';
}
[$c, $page] = http("$base/public/");
$tok = token_from($page);
[$c, $login] = http("$base/auth/login.php",
    ['email' => 'aishah@student.uthm.edu.my', 'password' => 'password123', '_csrf_token' => $tok],
    ['X-CSRF-Token: ' . $tok, 'Accept: application/json']);
check('buyer login ok', strpos($login, '"success":true') !== false, $pass, $fail);

[$c, $page] = http("$base/dashboard/buyer.php");
check('buyer.php renders (200, no fatal)', $c === 200 && strpos($page, 'Fatal error') === false, $pass, $fail, "http $c");

[$c, $body] = http("$base/dashboard/buyer.php?action=get_products&q=");
$data = json_decode($body, true);
check('get_products returns JSON', is_array($data) && ($data['success'] ?? false), $pass, $fail, "http $c");
$prods = $data['products'] ?? [];
check('every product row carries ai_adjustment', $prods && array_key_exists('ai_adjustment', $prods[0]), $pass, $fail);
$dupes = 0;
foreach ($prods as $p) {
    if ($p['ai_trust_score'] !== null && (float)$p['ai_adjustment'] >= 0 && (float)$p['fraud_risk'] < 25
        && abs((float)$p['ai_trust_score'] - (float)$p['trust_baseline']) < 0.5) $dupes++;
}
echo "  ($dupes of " . count($prods) . " searched products would have shown a duplicate AI number under the old rule)\n";
@unlink($jar);

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
