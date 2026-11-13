<?php
// scratch/ai_status.php - CLI-only, READ-ONLY diagnostic of the AI Trust Layer.
// Reports whether the engine is enabled, whether it has computed scores, and
// how many profiles currently carry risk. Makes NO writes.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/config/ai_trust.php';

function q(PDO $p, string $sql) { return $p->query($sql)->fetchColumn(); }

echo "=== TrustMart AI Trust Layer - status (read-only) ===\n";
echo "Model version      : " . AI_TRUST_MODEL_VERSION . "\n";
echo "AI_TRUST_ENABLED   : " . (AI_TRUST_ENABLED ? 'true' : 'FALSE (layer skipped)') . "\n";
echo "LLM enhancement    : " . (AI_TRUST_LLM_ENABLED && AI_TRUST_LLM_API_KEY !== '' ? 'ON' : 'OFF (pure-PHP heuristics)') . "\n";
echo "Hold threshold     : fraud_risk >= " . AI_TRUST_HOLD_RISK . " AND ai_hold=1\n";
echo "Max AI penalty cap : " . AI_TRUST_MAX_PENALTY . "\n\n";

$hasProfiles = (bool) q($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='trust_profiles'");
$hasSignals  = (bool) q($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='trust_ai_signals'");
$hasReviewAi = (bool) q($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='review_ai_credibility'");
echo "Table trust_profiles        : " . ($hasProfiles ? 'present' : 'MISSING') . "\n";
echo "Table trust_ai_signals      : " . ($hasSignals ? 'present' : 'MISSING') . "\n";
echo "Table review_ai_credibility : " . ($hasReviewAi ? 'present' : 'MISSING') . "\n";
if (!$hasProfiles) { echo "\ncannot continue - core table missing\n"; exit(1); }

$total   = (int) q($pdo, "SELECT COUNT(*) FROM trust_profiles");
$calced  = (int) q($pdo, "SELECT COUNT(*) FROM trust_profiles WHERE ai_calculated_at IS NOT NULL");
$scored  = (int) q($pdo, "SELECT COUNT(*) FROM trust_profiles WHERE ai_trust_score IS NOT NULL");
$riskPos = (int) q($pdo, "SELECT COUNT(*) FROM trust_profiles WHERE fraud_risk > 0");
$held    = (int) q($pdo, "SELECT COUNT(*) FROM trust_profiles WHERE fraud_risk >= " . AI_TRUST_HOLD_RISK . " AND ai_hold = 1");
$lastRun = q($pdo, "SELECT MAX(ai_calculated_at) FROM trust_profiles WHERE ai_calculated_at IS NOT NULL");

echo "\n--- Has the engine actually produced output? ---\n";
echo "role profiles total           : $total\n";
echo "profiles with ai_calculated_at: $calced  " . ($calced > 0 ? '(=> worker HAS run)' : '(=> NEVER run / not functioning)') . "\n";
echo "profiles with ai_trust_score  : $scored\n";
echo "profiles with fraud_risk > 0  : $riskPos " . ($riskPos > 0 ? '' : '(all clean - expected; seed demo to show a flagged case)') . "\n";
echo "profiles currently HELD       : $held\n";
echo "last ai_calculated_at         : " . ($lastRun ?: 'NULL') . "\n";

if ($hasSignals) {
    $sig = (int) q($pdo, "SELECT COUNT(*) FROM trust_ai_signals");
    echo "\ntrust_ai_signals rows         : $sig\n";
    if ($sig) {
        echo "breakdown by signal_code:\n";
        $r = $pdo->query("SELECT signal_code, COUNT(*) c, ROUND(AVG(penalty),2) avg_pen FROM trust_ai_signals GROUP BY signal_code ORDER BY c DESC");
        foreach ($r as $row) {
            echo sprintf("   - %-20s x%-3d  avg penalty %s\n", $row['signal_code'], $row['c'], $row['avg_pen']);
        }
    }
}

echo "\n--- Top 5 profiles by fraud_risk ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
$idCol = in_array('id', $cols, true) ? 'id' : (in_array('user_id', $cols, true) ? 'user_id' : $cols[0]);
$nameCol = in_array('name', $cols, true) ? 'name' : $cols[1];
$r = $pdo->query("SELECT tp.user_id, tp.profile_role, u.{$nameCol} AS nm, tp.score AS baseline, tp.ai_adjustment, tp.ai_trust_score, tp.fraud_risk, tp.ai_hold, tp.ai_flags
                    FROM trust_profiles tp JOIN users u ON u.{$idCol}=tp.user_id ORDER BY tp.fraud_risk DESC, tp.ai_adjustment ASC LIMIT 5");
foreach ($r as $row) {
    echo sprintf("   uid=%-4d %-8s %-18s baseline=%-6s adj=%-7s ai=%-6s risk=%-3d hold=%s flags=%s\n",
        $row['user_id'], $row['profile_role'], mb_substr((string)$row['nm'], 0, 18),
        $row['baseline'] ?? '-', $row['ai_adjustment'] ?? '-', $row['ai_trust_score'] ?? '-',
        $row['fraud_risk'] ?? 0, $row['ai_hold'] ?? '-', $row['ai_flags'] ?? '-');
}

if (!AI_TRUST_ENABLED) {
    $verdict = 'AI layer is DISABLED by config';
} elseif ($calced > 0) {
    $verdict = 'AI layer IS FUNCTIONING (has computed scores)';
} else {
    $verdict = 'AI code present but HAS NOT RUN yet -> execute: php ai_trust_worker.php';
}
echo "\nVERDICT: $verdict\n";
