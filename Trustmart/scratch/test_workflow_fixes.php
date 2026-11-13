<?php
// ============================================================
// scratch/test_workflow_fixes.php
// Verifies the W1–W4 workflow fixes without needing HTTP.
// Self-cleaning: creates throwaway rows and removes them.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/../includes/trust_score.php';

$pass = 0; $fail = 0;
function ok($label) { global $pass; $pass++; echo "  ✓ $label\n"; }
function bad($label, $detail = '') { global $fail; $fail++; echo "  ✗ $label  $detail\n"; }
function check($cond, $label, $detail = '') { $cond ? ok($label) : bad($label, $detail); }

echo "== W3: payment_input() replaces FILTER_SANITIZE_STRING ==\n";
$_REQUEST = [];
check(payment_input('missing') === null, 'missing key returns null');

$_REQUEST = ['ref' => '  TM20260101ABC123  '];
check(payment_input('ref', 64, '/^[A-Za-z0-9_\-]+$/') === 'TM20260101ABC123', 'trims whitespace on valid ref');

$_REQUEST = ['ref' => "evil'; DROP TABLE users;--"];
check(payment_input('ref', 64, '/^[A-Za-z0-9_\-]+$/') === null, 'rejects SQL-ish characters via pattern');

$_REQUEST = ['ref' => str_repeat('A', 200)];
check(payment_input('ref', 64) === str_repeat('A', 64), 'truncates over-long input');

$_REQUEST = ['amt' => '12.34'];
check(payment_input('amt', 16, '/^\d+(\.\d{1,2})?$/') === '12.34', 'accepts well-formed amount');

$_REQUEST = ['amt' => '12.345678'];
check(payment_input('amt', 16, '/^\d+(\.\d{1,2})?$/') === null, 'rejects over-precise amount');

$_REQUEST = ['sig' => 'deadBEEF0123'];
check(payment_input('sig', 128, '/^[a-fA-F0-9]+$/') === 'deadBEEF0123', 'accepts hex signature');

$_REQUEST = [];

echo "\n== W1: apply_payment_gateway_result — single authoritative writer ==\n";

// Find a real product + seller to build a throwaway pending payment.
$seed = $pdo->query(
    "SELECT p.product_id, p.seller_id, p.price, t.buyer_id
       FROM products p
       JOIN transactions t ON t.product_id = p.product_id
      WHERE p.status != 'Dipadam'
      LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (!$seed) { bad('no seed data available', 'aborting W1/W2 checks'); }
else {
    $product_id = (int) $seed['product_id'];
    $seller_id  = (int) $seed['seller_id'];
    $buyer_id   = (int) $seed['buyer_id'];
    $amount     = (float) $seed['price'];

    // Create throwaway transaction (Menunggu + unpaid).
    $pdo->prepare(
        "INSERT INTO transactions
            (buyer_id, seller_id, product_id, quantity, total_amount,
             payment_method, payment_type, payment_status, status, created_at)
         VALUES (?, ?, ?, 1, ?, 'fpx', 'online', 'unpaid', 'Menunggu', NOW())"
    )->execute([$buyer_id, $seller_id, $product_id, $amount]);
    $txn_id = (int) $pdo->lastInsertId();

    // Attach a pending payment.
    $ref = 'TESTW1' . strtoupper(bin2hex(random_bytes(4)));
    $pdo->prepare(
        "INSERT INTO payments (transaction_id, payment_reference, payment_method, amount, currency, status)
         VALUES (?, ?, 'fpx', ?, 'MYR', 'pending')"
    )->execute([$txn_id, $ref, $amount]);

    $r = apply_payment_gateway_result($pdo, $ref, true, json_encode(['test' => 'success']));
    check($r['ok'] && $r['state'] === 'completed', 'first success returns state=completed');

    $row = $pdo->prepare("SELECT status FROM payments WHERE payment_reference = ?");
    $row->execute([$ref]); $pstat = $row->fetchColumn();
    $row = $pdo->prepare("SELECT payment_status FROM transactions WHERE transaction_id = ?");
    $row->execute([$txn_id]); $tstat = $row->fetchColumn();
    check($pstat === 'completed', 'payments.status flipped to completed', "got: $pstat");
    check($tstat === 'paid',      'transactions.payment_status flipped to paid', "got: $tstat");

    // Idempotency: replay the same success.
    $r = apply_payment_gateway_result($pdo, $ref, true, json_encode(['test' => 'replay']));
    check($r['ok'] && $r['state'] === 'already_processed', 'duplicate success returns already_processed (idempotent)');

    // CRITICAL REGRESSION: A late "failed" webhook after completion must NOT downgrade.
    $r = apply_payment_gateway_result($pdo, $ref, false, json_encode(['test' => 'late failure']));
    check($r['ok'] && $r['state'] === 'failed', 'late failure notification returns failed state');

    $row->execute([$txn_id]); $tstat2 = $row->fetchColumn();
    check($tstat2 === 'paid', 'transactions.payment_status stays PAID despite late failure (this was the bug)', "got: $tstat2");

    $row = $pdo->prepare("SELECT status FROM payments WHERE payment_reference = ?");
    $row->execute([$ref]); $pstat2 = $row->fetchColumn();
    check($pstat2 === 'completed', 'payments.status stays COMPLETED despite late failure', "got: $pstat2");

    // Cleanup W1 txn/payments.
    $pdo->prepare("DELETE FROM payments WHERE transaction_id = ?")->execute([$txn_id]);
    $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([$txn_id]);

    echo "\n== W2: pay.php — stale pending rows are superseded ==\n";

    // Simulate a stale pending row for a fresh txn.
    $pdo->prepare(
        "INSERT INTO transactions
            (buyer_id, seller_id, product_id, quantity, total_amount,
             payment_method, payment_type, payment_status, status, created_at)
         VALUES (?, ?, ?, 1, ?, 'fpx', 'online', 'unpaid', 'Menunggu', NOW())"
    )->execute([$buyer_id, $seller_id, $product_id, $amount]);
    $txn2 = (int) $pdo->lastInsertId();

    // Insert a stale pending row (as if a prior pay.php attempt was abandoned).
    $stale_ref = 'TESTW2S' . strtoupper(bin2hex(random_bytes(4)));
    $pdo->prepare(
        "INSERT INTO payments (transaction_id, payment_reference, payment_method, amount, currency, status)
         VALUES (?, ?, 'fpx', ?, 'MYR', 'pending')"
    )->execute([$txn2, $stale_ref, $amount]);
    // And a processing row (must NOT be superseded — active ToyyibPay bill).
    $proc_ref = 'TESTW2P' . strtoupper(bin2hex(random_bytes(4)));
    $pdo->prepare(
        "INSERT INTO payments (transaction_id, payment_reference, payment_method, amount, currency, status)
         VALUES (?, ?, 'toyyibpay', ?, 'MYR', 'processing')"
    )->execute([$txn2, $proc_ref, $amount]);

    // Replicate the W2 block from pay.php manually (the file redirects/exits).
    $stale = $pdo->prepare("SELECT payment_id FROM payments WHERE transaction_id = ? AND status = 'pending'");
    $stale->execute([$txn2]);
    $stale_ids = $stale->fetchAll(PDO::FETCH_COLUMN);
    check(count($stale_ids) === 1, 'W2 selector finds exactly the pending row', 'count=' . count($stale_ids));
    if ($stale_ids) {
        $fail_q = $pdo->prepare(
            "UPDATE payments SET status = 'failed', gateway_response = ?
              WHERE payment_id IN (" . implode(',', array_fill(0, count($stale_ids), '?')) . ")"
        );
        $fail_q->execute(array_merge(
            [json_encode(['reason' => 'superseded_by_retry'])],
            $stale_ids
        ));
    }
    $chk = $pdo->prepare("SELECT status FROM payments WHERE payment_reference = ?");
    $chk->execute([$stale_ref]);
    check($chk->fetchColumn() === 'failed', 'W2 stale pending row is now failed');

    $chk->execute([$proc_ref]);
    check($chk->fetchColumn() === 'processing', 'W2 processing row is preserved (never downgraded by retry)');

    // Cleanup.
    $pdo->prepare("DELETE FROM payments WHERE transaction_id = ?")->execute([$txn2]);
    $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([$txn2]);

    echo "\n== W4: product.php trust display — Baharu vs score ==\n";

    // 4a: brand-new seller with no trust_profiles evidence.
    $empty = trust_profile_from_row([], 'trust_');
    check(!trust_has_evidence($empty), 'W4 no-evidence profile is treated as no-evidence');
    check(trust_score_display($empty) === 'Baharu', 'W4 display for new seller is "Baharu"', 'got: ' . trust_score_display($empty));
    check(trust_score_color($empty) === 'var(--ink4)', 'W4 color for new seller is grey (ink4)');

    // 4b: seller with an established Bayesian profile.
    $established = [
        'trust_role'          => 'penjual',
        'trust_score'         => 87.5,
        'trust_raw_score'     => 84.0,
        'trust_confidence'    => 92.0,
        'trust_confidence_label' => 'Tinggi',
        'trust_level'         => 'Dipercayai',
        'trust_evidence'      => 12,
        'trust_counterparties'=> 8,
        'trust_completed'     => 10,
    ];
    $prof = trust_profile_from_row($established, 'trust_');
    check(trust_has_evidence($prof), 'W4 established profile has evidence');
    check(trust_score_display($prof) === '88/100', 'W4 display formats score with /100', 'got: ' . trust_score_display($prof));
    check(trust_score_color($prof) === 'var(--green)', 'W4 color for score>=85 is green');
}

echo "\n========================================\n";
echo "PASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
