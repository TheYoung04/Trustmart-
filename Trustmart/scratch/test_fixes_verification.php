<?php
/**
 * Verification test for the three payment/order fixes.
 * Run: php scratch/test_fixes_verification.php
 *
 * Covers:
 *  - Fix 1: gateway_sim.php uses defined CSRF helpers
 *  - Fix 3: process_payment_refund() no longer rolls back a caller's transaction
 *  - DB connectivity
 */

$root = dirname(__DIR__);
require_once $root . '/includes/csrf.php';
require_once $root . '/config/payment.php';

$pass = 0;
$fail = 0;
function check($name, $cond, &$pass, &$fail) {
    if ($cond) { echo "  [PASS] $name\n"; $pass++; }
    else       { echo "  [FAIL] $name\n"; $fail++; }
}

echo "== Fix 1: CSRF helper functions exist ==\n";
check('csrf_validate() is defined', function_exists('csrf_validate'), $pass, $fail);
check('csrf_input() is defined', function_exists('csrf_input'), $pass, $fail);
check('Old verify_csrf_token() should NOT be required', !function_exists('verify_csrf_token'), $pass, $fail);

// Static check: gateway_sim.php must not call the removed functions
$sim = file_get_contents($root . '/payment/gateway_sim.php');
check('gateway_sim no longer calls verify_csrf_token()', strpos($sim, 'verify_csrf_token(') === false, $pass, $fail);
check('gateway_sim no longer calls generate_csrf_token()', strpos($sim, 'generate_csrf_token(') === false, $pass, $fail);
check('gateway_sim calls csrf_validate()', strpos($sim, 'csrf_validate(') !== false, $pass, $fail);
check('gateway_sim outputs csrf_input()', strpos($sim, 'csrf_input(') !== false, $pass, $fail);

echo "\n== Fix 2: callback.php variable scope ==\n";
$cb = file_get_contents($root . '/payment/callback.php');
// The ToyyibPay branch must use $payment_check (defined earlier), never $payment before line 95
check('callback.php ToyyibPay branch uses $payment_check',
    strpos($cb, "\$payment_check['payment_method'] === 'toyyibpay'") !== false, $pass, $fail);
check('callback.php does NOT reference undefined $payment before lock',
    preg_match('/if \(\$payment\[\'payment_method\'\]/', $cb) === 0, $pass, $fail);

echo "\n== Fix 3: nested-transaction safety (live DB) ==\n";
try {
    $pdo = new PDO('mysql:host=localhost;dbname=trustmart', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "  [INFO] DB connection OK\n";

    // Begin an OUTER transaction (mimics buyer/seller cancel flow).
    $pdo->beginTransaction();

    // Call the refund fn for a payment id that does not exist.
    // Old code: internal beginTransaction() throws -> caught -> rolls back OUTER txn.
    // New code: ownTransaction=false, skips begin, returns failure, OUTER txn intact.
    $result = process_payment_refund($pdo, 99999999, 'test', 'auto');

    check('process_payment_refund returned failure for missing payment', $result['success'] === false, $pass, $fail);
    check('CALLER transaction still active after refund call (THE FIX)', $pdo->inTransaction() === true, $pass, $fail);

    // Roll back (leave DB untouched) to confirm the txn object is still usable.
    $pdo->rollBack();
    check('Rollback of caller transaction succeeded cleanly', $pdo->inTransaction() === false, $pass, $fail);

    // Standalone path: no surrounding transaction.
    $r2 = process_payment_refund($pdo, 99999999, 'test', 'auto');
    check('Standalone call manages own txn without error', $r2['success'] === false && $pdo->inTransaction() === false, $pass, $fail);

} catch (PDOException $e) {
    echo "  [FAIL] DB error: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n==================================\n";
echo "RESULT: $pass passed, $fail failed\n";
echo "==================================\n";
exit($fail === 0 ? 0 : 1);
