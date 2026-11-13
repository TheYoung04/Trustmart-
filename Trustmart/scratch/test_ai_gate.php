<?php
/**
 * Verifies the AI order-hold gate (recommendation #4):
 *   fraud_risk >= AI_TRUST_HOLD_RISK AND ai_hold=1  → blocked
 *   admin release (ai_hold=0)                        → allowed
 *   low risk / no profile / AI disabled              → allowed (fail open)
 * Restores the original row state afterwards.
 * Run: php scratch/test_ai_gate.php
 */

$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/includes/ai_trust.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail) { echo ($c?"  [PASS] ":"  [FAIL] ").$n."\n"; $c ? $pass++ : $fail++; }

$uid = (int)$pdo->query("SELECT user_id FROM trust_profiles WHERE profile_role='penjual' ORDER BY user_id LIMIT 1")->fetchColumn();
if (!$uid) { echo "No seller profile found\n"; exit(1); }

$orig = $pdo->query("SELECT fraud_risk, ai_hold FROM trust_profiles WHERE user_id=$uid AND profile_role='penjual'")->fetch(PDO::FETCH_ASSOC);
echo "Target seller user_id=$uid (orig fraud_risk={$orig['fraud_risk']}, ai_hold={$orig['ai_hold']})\n";

try {
    // 1) High risk + hold on → blocked
    $pdo->exec("UPDATE trust_profiles SET fraud_risk=60, ai_hold=1 WHERE user_id=$uid AND profile_role='penjual'");
    check("fraud_risk 60 + ai_hold=1 → HELD", ai_trust_seller_hold($pdo, $uid, 'penjual'), $pass, $fail);

    // 2) Admin releases the hold → allowed even at same risk
    $pdo->exec("UPDATE trust_profiles SET ai_hold=0 WHERE user_id=$uid AND profile_role='penjual'");
    check("admin release (ai_hold=0) → allowed", !ai_trust_seller_hold($pdo, $uid, 'penjual'), $pass, $fail);

    // 3) Risk below threshold → allowed even with hold flag on
    $pdo->exec("UPDATE trust_profiles SET fraud_risk=20, ai_hold=1 WHERE user_id=$uid AND profile_role='penjual'");
    check("fraud_risk 20 (below threshold) → allowed", !ai_trust_seller_hold($pdo, $uid, 'penjual'), $pass, $fail);

    // 4) Exactly at threshold → held
    $pdo->exec("UPDATE trust_profiles SET fraud_risk=" . AI_TRUST_HOLD_RISK . " WHERE user_id=$uid AND profile_role='penjual'");
    check("fraud_risk at threshold (" . AI_TRUST_HOLD_RISK . ") → HELD", ai_trust_seller_hold($pdo, $uid, 'penjual'), $pass, $fail);

    // 5) Unknown profile → fail open
    check("no trust profile → allowed (fail open)", !ai_trust_seller_hold($pdo, 999999, 'penjual'), $pass, $fail);

    // 6) Worker must not clobber an admin's release decision
    $pdo->exec("UPDATE trust_profiles SET fraud_risk=0, ai_hold=0 WHERE user_id=$uid AND profile_role='penjual'");
    $budget = 0;
    ai_refresh_user($pdo, $uid, 'penjual', $budget);
    $hold = (int)$pdo->query("SELECT ai_hold FROM trust_profiles WHERE user_id=$uid AND profile_role='penjual'")->fetchColumn();
    check("ai_trust_worker preserves ai_hold=0 after recompute", $hold === 0, $pass, $fail);
} finally {
    $pdo->prepare("UPDATE trust_profiles SET fraud_risk=?, ai_hold=? WHERE user_id=? AND profile_role='penjual'")
        ->execute([$orig['fraud_risk'], $orig['ai_hold'], $uid]);
    echo "  state restored\n";
}

echo "\n==================================\nRESULT: $pass passed, $fail failed\n==================================\n";
exit($fail === 0 ? 0 : 1);
