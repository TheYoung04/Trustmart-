<?php
/**
 * Proves the AI detectors actually FIRE on seeded adversarial patterns.
 * Seeds circular trading, a scam chat message and a low-effort review for one
 * seller, runs the engine, asserts flags, then cleans up.
 * Run: php scratch/test_ai_engine.php
 */

$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/includes/ai_trust_engine.php';
require_once $root . '/includes/ai_trust.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail) { echo ($c?"  [PASS] ":"  [FAIL] ").$n."\n"; $c ? $pass++ : $fail++; }

// ── Unit: heuristic credibility ──────────────────────────────
echo "== Engine unit: heuristic review credibility ==\n";
[$gCred] = ai_heuristic_review_credibility("Barang sampai cepat, packaging kemas, penjual jujur dan berbaloi. Sangat puas hati!", 5);
[$fCred] = ai_heuristic_review_credibility("good good", 5);
[$mCred] = ai_heuristic_review_credibility("teruk sangat rosak barang tipu", 5);
echo "  genuine=$gCred generic=$fCred mismatch=$mCred\n";
check("genuine review scores higher than generic", $gCred > $fCred, $pass, $fail);
check("generic low-effort review <= 55", $fCred <= 55, $pass, $fail);
check("negative-text/high-star mismatch penalised <= 50", $mCred <= 50, $pass, $fail);

// ── Discover targets ─────────────────────────────────────────
echo "\n== Integration: detectors fire on seeded adversarial data ==\n";
$S = $pdo->query("SELECT user_id FROM trust_profiles WHERE profile_role='penjual' ORDER BY user_id LIMIT 1")->fetchColumn();
$S = (int)$S;
// counterpart B = another user, not S
$B = (int)$pdo->query("SELECT user_id FROM users WHERE user_id <> $S AND role='pembeli' LIMIT 1")->fetchColumn();
if (!$B) $B = (int)$pdo->query("SELECT user_id FROM users WHERE user_id <> $S LIMIT 1")->fetchColumn();
$prod = $pdo->query("SELECT product_id FROM products WHERE status='Aktif' LIMIT 1")->fetchColumn();
echo "  seller S=$S  counterpart B=$B  product=$prod\n";

$made = ['txn'=>[], 'review'=>[], 'msg'=>[]];
function mkTxn($pdo, $seller, $buyer, $prod, &$made) {
    $st = $pdo->prepare("INSERT INTO transactions
        (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, payment_type, payment_status, status, completed_by)
        VALUES (?, ?, ?, 1, 10.00, 'Tunai','cash','paid','Selesai','buyer')");
    $st->execute([$buyer, $seller, $prod]);
    $id = (int)$pdo->lastInsertId(); $made['txn'][] = $id; return $id;
}
$budget = 0; // LLM disabled in test

try {
    // 1) Circular trading: S sells to B AND B sells to S
    $t1 = mkTxn($pdo, $S, $B, $prod, $made);
    $t2 = mkTxn($pdo, $B, $S, $prod, $made);

    // 2) Low-effort review by B on S (via t1)
    $rv = $pdo->prepare("INSERT INTO reviews (transaction_id, buyer_id, seller_id, rating, comment) VALUES (?,?,?,?,?)");
    $rv->execute([$t1, $B, $S, 5, 'good good good']);
    $reviewId = (int)$pdo->lastInsertId(); $made['review'][] = $reviewId;

    // 3) Scam-language chat from S
    $ms = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, product_id, message_text) VALUES (?,?,?,?)");
    $ms->execute([$S, $B, $prod, 'pm saya no whatsapp 0123456789 bayar luar platform transfer terus']);
    $msgId = (int)$pdo->lastInsertId(); $made['msg'][] = $msgId;

    // Baseline before
    $base = (float)$pdo->query("SELECT score FROM trust_profiles WHERE user_id=$S AND profile_role='penjual'")->fetchColumn();

    $res = ai_refresh_user($pdo, $S, 'penjual', $budget);
    print_r($res);

    check("result not skipped", !isset($res['skipped']), $pass, $fail);
    check("circular_trading flagged", in_array('circular_trading', $res['flags'] ?? [], true), $pass, $fail);
    check("scam_language flagged", in_array('scam_language', $res['flags'] ?? [], true), $pass, $fail);
    check("low_review_credibility flagged", in_array('low_review_credibility', $res['flags'] ?? [], true), $pass, $fail);
    check("ai_adjustment is negative", $res['adjustment'] < 0, $pass, $fail);
    check("fraud_risk > 0", $res['fraud_risk'] > 0, $pass, $fail);
    check("final AI score below baseline", $res['final'] < $res['baseline'], $pass, $fail);

    // Persistence checks
    $row = $pdo->query("SELECT ai_trust_score, ai_flags FROM trust_profiles WHERE user_id=$S AND profile_role='penjual'")->fetch(PDO::FETCH_ASSOC);
    check("ai_flags persisted to trust_profiles", !empty($row['ai_flags']), $pass, $fail);
    $sigCount = (int)$pdo->query("SELECT COUNT(*) FROM trust_ai_signals WHERE user_id=$S AND profile_role='penjual'")->fetchColumn();
    check("audit signal rows written", $sigCount >= 3, $pass, $fail);

    // Presentation renders without error
    $ai = ai_trust_fetch($pdo, $S, 'penjual');
    $html = ai_trust_detail_html($ai);
    check("detail html non-empty", strlen($html) > 20, $pass, $fail);
    check("badge html non-empty", strlen(ai_trust_badge_html($ai)) > 10, $pass, $fail);

    // Baseline score must be UNCHANGED
    $baseAfter = (float)$pdo->query("SELECT score FROM trust_profiles WHERE user_id=$S AND profile_role='penjual'")->fetchColumn();
    check("baseline Bayesian score untouched", abs($baseAfter - $base) < 0.001, $pass, $fail);

} finally {
    // ── Cleanup seeded rows (reviews->transactions cascade) ──
    foreach ($made['review'] as $id) { $pdo->prepare("DELETE FROM reviews WHERE review_id=?")->execute([$id]); }
    foreach ($made['msg'] as $id)   { $pdo->prepare("DELETE FROM messages WHERE message_id=?")->execute([$id]); }
    foreach ($made['txn'] as $id)   { $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$id]); }
    $pdo->prepare("DELETE FROM trust_ai_signals WHERE user_id=?")->execute([$S]);
    $pdo->prepare("UPDATE trust_profiles SET ai_trust_score=NULL, ai_adjustment=0, fraud_risk=0, ai_flags=NULL, review_credibility=NULL, ai_model_version=NULL, ai_calculated_at=NULL WHERE user_id=? AND profile_role='penjual'")->execute([$S]);
    echo "  cleanup done\n";
}

echo "\n==================================\nRESULT: $pass passed, $fail failed\n==================================\n";
exit($fail === 0 ? 0 : 1);
