<?php
/**
 * Persistent demo seed for the AI Trust Layer hold gate.
 *
 * Crafts REAL adversarial evidence (scam-language chat, and reciprocal
 * trades if still needed) for one seller, then lets the engine COMPUTE
 * fraud_risk. Never fakes the number. Leaves ai_hold=0 so the demo story
 * is: admin sees high risk in the Lapisan AI panel → presses "Kenakan
 * Tahanan" → buyer checkout is blocked 403 → admin releases → succeeds.
 *
 * Run: php scratch/seed_ai_demo.php [seller_user_id]
 * Safe to re-run: only tops up scam messages until the threshold is met.
 */

$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/includes/ai_trust_engine.php';
require_once $root . '/includes/ai_trust.php';

echo "== TrustMart seed_ai_demo — drive one seller to fraud_risk >= " . AI_TRUST_HOLD_RISK . " ==\n";

// ── 1. Resolve target seller ─────────────────────────────────
$target = isset($argv[1]) ? (int) $argv[1] : 0;
if ($target) {
    $row = $pdo->prepare("SELECT user_id FROM users WHERE user_id=? AND role='penjual'");
    $row->execute([$target]);
    $S = (int) ($row->fetchColumn() ?: 0);
} else {
    // Prefer an already-flagged (but sub-threshold) seller: risk story starts mid-way.
    $S = (int) $pdo->query(
        "SELECT tp.user_id FROM trust_profiles tp
           JOIN users u ON u.user_id = tp.user_id
          WHERE tp.profile_role='penjual' AND u.role='penjual'
            AND tp.fraud_risk > 0 AND tp.fraud_risk < " . AI_TRUST_HOLD_RISK . "
          ORDER BY tp.fraud_risk DESC LIMIT 1"
    )->fetchColumn();
    if (!$S) {
        $S = (int) $pdo->query("SELECT user_id FROM users WHERE role='penjual' LIMIT 1")->fetchColumn();
    }
}
if (!$S) { echo "No PENJUAL user found — aborting.\n"; exit(1); }

$u = $pdo->prepare("SELECT user_id, full_name AS name, email FROM users WHERE user_id=?");
$u->execute([$S]);
$seller = $u->fetch(PDO::FETCH_ASSOC);
echo "  target seller : #{$seller['user_id']} {$seller['name']} ({$seller['email']})\n";

$prof = $pdo->prepare("SELECT score, fraud_risk, ai_hold FROM trust_profiles WHERE user_id=? AND profile_role='penjual'");
$prof->execute([$S]);
$p0 = $prof->fetch(PDO::FETCH_ASSOC);
if (!$p0) { echo "Seller has no penjual trust profile — aborting.\n"; exit(1); }
echo "  before        : baseline={$p0['score']} fraud_risk={$p0['fraud_risk']} ai_hold={$p0['ai_hold']}\n";

// ── 2. Ensure the seller owns a buyable active product ───────
$prod = $pdo->prepare("SELECT product_id, name FROM products WHERE seller_id=? AND status='Aktif' AND stock>0 ORDER BY product_id LIMIT 1");
$prod->execute([$S]);
$P = $prod->fetch(PDO::FETCH_ASSOC);
if (!$P) {
    $ins = $pdo->prepare("INSERT INTO products (seller_id, name, category, description, price, stock, status)
                          VALUES (?, 'Powerbank 20000mAh (Demo AI)', 'Elektronik',
                                  'Produk demo untuk lintasan Lapisan AI. Stock cukup.', 45.00, 10, 'Aktif')");
    $ins->execute([$S]);
    $P = ['product_id' => (int) $pdo->lastInsertId(), 'name' => 'Powerbank 20000mAh (Demo AI)'];
    echo "  product       : CREATED #{$P['product_id']} {$P['name']}\n";
} else {
    echo "  product       : existing #{$P['product_id']} {$P['name']}\n";
}

// ── 3. Counterpart buyer for the chat thread ─────────────────
$B = (int) $pdo->query(
    "SELECT COALESCE(
        (SELECT buyer_id FROM transactions WHERE seller_id=$S AND buyer_id<>$S LIMIT 1),
        (SELECT user_id FROM users WHERE role='pembeli' AND user_id<>$S LIMIT 1)
     )"
)->fetchColumn();
$b = $pdo->prepare("SELECT full_name FROM users WHERE user_id=?");
$b->execute([$B]);
$buyerName = $b->fetchColumn() ?: "?";
echo "  counterpart   : buyer #{$B} {$buyerName}\n";

// ── 4. Top up scam-language chat until sRisk saturates (4 hits = 100) ──
$scamTexts = [
    'kak, bayar luar platform je boleh tak? lebih murah sikit',
    'transfer bank terus ke akaun saya, jangan bayar dalam sistem',
    'saya boleh bagi diskaun kalau bayar luar trustmart',
    'esok transfer duit terus pun jadi, saya ship terus',
];
// count current engine-visible scam hits so re-runs don't spam duplicates
$hits = 0;
$stmt = $pdo->prepare("SELECT message_text FROM messages WHERE sender_id = ? ORDER BY created_at DESC LIMIT 500");
$stmt->execute([$S]);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $text) {
    foreach (AI_SCAM_PATTERNS as $p) {
        if (@preg_match($p, (string) $text) === 1) { $hits++; break; }
    }
}
echo "  scam msgs     : {$hits} already present\n";
$ms = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, product_id, message_text) VALUES (?,?,?,?)");
for ($i = $hits; $i < 4; $i++) {
    $ms->execute([$S, $B, $P['product_id'], $scamTexts[$i % 4]]);
    echo "  + seeded scam message #" . ($i + 1) . " ({$scamTexts[$i % 4]})\n";
}

// ── 5. Recompute via the real engine ─────────────────────────
$budget = 0;
$res = ai_refresh_user($pdo, $S, 'penjual', $budget);
$risk = (int) $pdo->query("SELECT fraud_risk FROM trust_profiles WHERE user_id=$S AND profile_role='penjual'")->fetchColumn();
echo "  after refresh : fraud_risk=$risk (skipped=" . json_encode($res['skipped'] ?? 'no') . ")\n";

// ── 6. Still below threshold? Add reciprocal trades (raises cRisk) ──
if ($risk < AI_TRUST_HOLD_RISK) {
    echo "  below threshold — seeding reciprocal completed trades (circular pattern)\n";
    $mk = $pdo->prepare("INSERT INTO transactions
        (buyer_id, seller_id, product_id, quantity, total_amount, payment_method, payment_type, payment_status, status, completed_by)
        VALUES (?, ?, ?, 1, 10.00, 'Tunai','cash','paid','Selesai','buyer')");
    // buyer -> seller and seller -> buyer, for two different demo buyers
    $others = $pdo->query("SELECT user_id FROM users WHERE role='pembeli' AND user_id<>$S LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($others as $O) {
        $mk->execute([(int)$O, $S, $P['product_id']]);
        $mk->execute([$S, (int)$O, $P['product_id']]);
        echo "  + reciprocal trades S=$S <-> B=$O\n";
    }
    $budget = 0;
    ai_refresh_user($pdo, $S, 'penjual', $budget);
    $risk = (int) $pdo->query("SELECT fraud_risk FROM trust_profiles WHERE user_id=$S AND profile_role='penjual'")->fetchColumn();
    echo "  after trades  : fraud_risk=$risk\n";
}

// ── 7. Report ────────────────────────────────────────────────
$final = $pdo->query("SELECT score, fraud_risk, ai_adjustment, ai_trust_score, ai_flags
                        FROM trust_profiles WHERE user_id=$S AND profile_role='penjual'")->fetch(PDO::FETCH_ASSOC);
echo "\n-- Final AI profile (seller #{$S}) --\n";
foreach (['baseline score (untouched)' => $final['score'], 'fraud_risk' => $final['fraud_risk'],
          'ai_adjustment' => $final['ai_adjustment'], 'ai_trust_score' => $final['ai_trust_score'],
          'flags' => $final['ai_flags']] as $k => $v) {
    echo "  $k: " . var_export($v, true) . "\n";
}
echo "\n-- Signals (trust_ai_signals) --\n";
$sig = $pdo->prepare("SELECT signal_code, signal_value, penalty, reason FROM trust_ai_signals WHERE user_id=? AND profile_role='penjual' ORDER BY penalty ASC");
$sig->execute([$S]);
foreach ($sig as $s) {
    echo "  {$s['signal_code']}: value={$s['signal_value']} penalty=-{$s['penalty']} ({$s['reason']})\n";
}

$ok = $final['fraud_risk'] >= AI_TRUST_HOLD_RISK;
echo "\nVERDICT: " . ($ok
    ? "READY — fraud_risk {$final['fraud_risk']} >= " . AI_TRUST_HOLD_RISK . ". ai_hold is " . $p0['ai_hold'] . " (gate arms when admin presses 'Kenakan Tahanan').\n"
    : "NOT YET — risk {$final['fraud_risk']} below " . AI_TRUST_HOLD_RISK . ". Check patterns above.\n");

echo "\n-- Demo runbook --\n";
echo "  1) Admin login (dashboard/admin.php) → tab Lapisan AI → seller #{$S} appears at risk {$final['fraud_risk']}.\n";
echo "  2) Buyer (any, e.g. #{$B}) opens product #{$P['product_id']} → AI badge + breakdown visible on detail page.\n";
echo "  3) Admin presses 'Kenakan Tahanan' → buyer retries checkout → 403 'Penjual ini sedang dalam siasatan PTU (Lapisan AI)'.\n";
echo "  4) Admin presses 'Lepaskan Tahanan' → checkout succeeds again (clean up the test order afterwards).\n";
echo "  Baseline Bayesian score stayed {$final['score']} (invariant: AI never alters the core score).\n";
