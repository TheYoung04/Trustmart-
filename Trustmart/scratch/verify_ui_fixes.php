<?php
/**
 * UI/UX Fixes Verification Script
 * 
 * Tests all 6 UI/UX errors to confirm they are resolved:
 * A5: Admin refunds tab
 * B6: Asset 404s
 * S9: Trust display consistency
 * S3: Payment status column
 * S7: Duplicate DOM IDs
 * A4/A8: Test data
 * 
 * Run: php verify_ui_fixes.php
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/trust_score.php';

echo "\n========================================\n";
echo "TrustMart UI/UX Fixes Verification\n";
echo "========================================\n\n";

$issues = [
    'A5' => 'Admin refunds tab',
    'B6' => 'Asset 404s',
    'S9' => 'Trust display',
    'S3' => 'Payment status column',
    'S7' => 'Duplicate DOM IDs',
    'A4' => 'Test data - disputes',
    'A8' => 'Test data - AI fraud hold'
];

$results = [];

// ════════════════════════════════════════════════════════════
// A5: Verify Admin Refunds Tab
// ════════════════════════════════════════════════════════════
echo "▶ A5: Admin Refunds Tab\n";
$a5_pass = true;
$checks = [];

// Check TABS array
$admin_php = file_get_contents(__DIR__ . '/../dashboard/admin.php');
if (strpos($admin_php, "'pulangan'") !== false) {
    $checks[] = "✓ 'pulangan' in TABS array";
} else {
    $checks[] = "✗ 'pulangan' NOT in TABS array";
    $a5_pass = false;
}

// Check tab container
if (strpos($admin_php, 'id="tab-pulangan"') !== false) {
    $checks[] = "✓ tab-pulangan container exists";
} else {
    $checks[] = "✗ tab-pulangan container missing";
    $a5_pass = false;
}

// Check loadRefunds function
if (strpos($admin_php, 'function loadRefunds') !== false) {
    $checks[] = "✓ loadRefunds() function exists";
} else {
    $checks[] = "✗ loadRefunds() function missing";
    $a5_pass = false;
}

// Check get_refunds action
if (strpos($admin_php, "case 'get_refunds'") !== false) {
    $checks[] = "✓ get_refunds backend action exists";
} else {
    $checks[] = "✗ get_refunds backend action missing";
    $a5_pass = false;
}

// Check retry_refund action
if (strpos($admin_php, "case 'retry_refund'") !== false) {
    $checks[] = "✓ retry_refund backend action exists";
} else {
    $checks[] = "✗ retry_refund backend action missing";
    $a5_pass = false;
}

foreach ($checks as $check) {
    echo "  $check\n";
}
$results['A5'] = $a5_pass ? '✓ PASS' : '✗ FAIL';
echo "\n";

// ════════════════════════════════════════════════════════════
// B6: Verify Asset Paths
// ════════════════════════════════════════════════════════════
echo "▶ B6: Asset Paths\n";
$b6_pass = true;
$asset_checks = [];

// Check CSS file exists
if (file_exists(__DIR__ . '/../public/assets/css/trustmart-ui.css')) {
    $asset_checks[] = "✓ CSS file exists";
} else {
    $asset_checks[] = "✗ CSS file NOT found";
    $b6_pass = false;
}

// Check JS file exists
if (file_exists(__DIR__ . '/../public/assets/js/trustmart-ui.js')) {
    $asset_checks[] = "✓ JS file exists";
} else {
    $asset_checks[] = "✗ JS file NOT found";
    $b6_pass = false;
}

// Check header.php uses smart path detection
$header_php = file_get_contents(__DIR__ . '/../includes/header.php');
if (strpos($header_php, '$__asset_base') !== false) {
    $asset_checks[] = "✓ header.php uses smart path detection";
} else {
    $asset_checks[] = "✗ header.php missing path detection";
    $b6_pass = false;
}

// Check PAYMENT_BASE_URL is defined
if (strpos(file_get_contents(__DIR__ . '/../config/payment.php'), 'PAYMENT_BASE_URL') !== false) {
    $asset_checks[] = "✓ PAYMENT_BASE_URL constant defined";
} else {
    $asset_checks[] = "✗ PAYMENT_BASE_URL constant missing";
    $b6_pass = false;
}

foreach ($asset_checks as $check) {
    echo "  $check\n";
}
$results['B6'] = $b6_pass ? '✓ PASS' : '✗ FAIL';
echo "\n";

// ════════════════════════════════════════════════════════════
// S9: Verify Trust Display
// ════════════════════════════════════════════════════════════
echo "▶ S9: Trust Display Consistency\n";
$s9_pass = true;
$trust_checks = [];

// Check trust_score_display function
$trust_score_php = file_get_contents(__DIR__ . '/../includes/trust_score.php');
if (strpos($trust_score_php, 'function trust_score_display') !== false) {
    $trust_checks[] = "✓ trust_score_display() function exists";
    
    // Check if it returns 'Baharu'
    if (preg_match('/return\s+[\'"]Baharu[\'"]/', $trust_score_php)) {
        $trust_checks[] = "✓ Returns 'Baharu' for new users";
    } else {
        $trust_checks[] = "✗ Does not return 'Baharu'";
        $s9_pass = false;
    }
} else {
    $trust_checks[] = "✗ trust_score_display() function missing";
    $s9_pass = false;
}

// Check profile.php uses it
$profile_php = file_get_contents(__DIR__ . '/../dashboard/profile.php');
if (strpos($profile_php, 'trust_score_display') !== false) {
    $trust_checks[] = "✓ profile.php uses trust_score_display()";
} else {
    $trust_checks[] = "✗ profile.php NOT using trust_score_display()";
    $s9_pass = false;
}

// Check buyer.php uses it
$buyer_php = file_get_contents(__DIR__ . '/../dashboard/buyer.php');
if (strpos($buyer_php, 'trust_score_display') !== false) {
    $trust_checks[] = "✓ buyer.php uses trust_score_display()";
} else {
    $trust_checks[] = "✗ buyer.php NOT using trust_score_display()";
    $s9_pass = false;
}

foreach ($trust_checks as $check) {
    echo "  $check\n";
}
$results['S9'] = $s9_pass ? '✓ PASS' : '✗ FAIL';
echo "\n";

// ════════════════════════════════════════════════════════════
// S3: Verify Payment Status Column
// ════════════════════════════════════════════════════════════
echo "▶ S3: Payment Status Column\n";
$s3_pass = true;
$payment_checks = [];

$seller_php = file_get_contents(__DIR__ . '/../dashboard/seller.php');

// Check table header includes 'Bayaran'
if (preg_match('/<th>.*Bayaran.*<\/th>/', $seller_php)) {
    $payment_checks[] = "✓ Table header includes 'Bayaran' column";
} else {
    $payment_checks[] = "✗ Table header missing 'Bayaran' column";
    $s3_pass = false;
}

// Check payment_status is fetched
if (strpos($seller_php, 't.payment_status') !== false) {
    $payment_checks[] = "✓ payment_status fetched from database";
} else {
    $payment_checks[] = "✗ payment_status NOT fetched";
    $s3_pass = false;
}

// Check payment_status_info function
if (strpos($seller_php, 'function payment_status_info') !== false) {
    $payment_checks[] = "✓ payment_status_info() function exists";
} else {
    $payment_checks[] = "✗ payment_status_info() function missing";
    $s3_pass = false;
}

// Check payment status is rendered
if (preg_match('/data-role="pay-badge"/', $seller_php)) {
    $payment_checks[] = "✓ Payment status rendered in table";
} else {
    $payment_checks[] = "✗ Payment status NOT rendered";
    $s3_pass = false;
}

foreach ($payment_checks as $check) {
    echo "  $check\n";
}
$results['S3'] = $s3_pass ? '✓ PASS' : '✗ FAIL';
echo "\n";

// ════════════════════════════════════════════════════════════
// S7: Verify No Duplicate DOM IDs
// ════════════════════════════════════════════════════════════
echo "▶ S7: No Duplicate DOM IDs\n";
$s7_pass = true;
$dom_checks = [];

// Check using data-order-row instead of id
if (strpos($seller_php, 'data-order-row') !== false) {
    $dom_checks[] = "✓ Using data-order-row attribute";
    
    // Count how many times id="order-row- appears
    $id_count = substr_count($seller_php, 'id="order-row-');
    if ($id_count === 0) {
        $dom_checks[] = "✓ No duplicate order-row IDs found";
    } else {
        $dom_checks[] = "✗ Found {$id_count} order-row IDs (should use data-order-row)";
        $s7_pass = false;
    }
} else {
    $dom_checks[] = "✗ NOT using data-order-row attribute";
    $s7_pass = false;
}

// Check querySelectorAll uses data-order-row
if (strpos($seller_php, '[data-order-row="') !== false) {
    $dom_checks[] = "✓ JavaScript uses [data-order-row] selector";
} else {
    $dom_checks[] = "✗ JavaScript NOT using [data-order-row] selector";
    $s7_pass = false;
}

foreach ($dom_checks as $check) {
    echo "  $check\n";
}
$results['S7'] = $s7_pass ? '✓ PASS' : '✗ FAIL';
echo "\n";

// ════════════════════════════════════════════════════════════
// A4/A8: Verify Test Data Documentation
// ════════════════════════════════════════════════════════════
echo "▶ A4/A8: Test Data Documentation\n";
$test_checks = [];
$test_pass = true;

// Check for UAT test plan
if (file_exists(__DIR__ . '/../UAT_TEST_PLAN.md')) {
    $test_checks[] = "✓ UAT_TEST_PLAN.md exists";
} else {
    $test_checks[] = "✗ UAT_TEST_PLAN.md NOT found";
    $test_pass = false;
}

// Check for test scripts
$test_scripts = [
    'test_pembeli_role.php',
    'test_penjual_role.php',
    'test_pentadbir_role.php'
];

foreach ($test_scripts as $script) {
    if (file_exists(__DIR__ . '/' . $script)) {
        $test_checks[] = "✓ {$script} exists";
    } else {
        $test_checks[] = "✗ {$script} NOT found";
        $test_pass = false;
    }
}

// Check for deep audit report
if (file_exists(__DIR__ . '/../DEEP_AUDIT_REPORT.md')) {
    $test_checks[] = "✓ DEEP_AUDIT_REPORT.md exists";
} else {
    $test_checks[] = "✗ DEEP_AUDIT_REPORT.md NOT found";
    $test_pass = false;
}

foreach ($test_checks as $check) {
    echo "  $check\n";
}
$results['A4'] = $test_pass ? '✓ PASS' : '✗ FAIL';
$results['A8'] = $test_pass ? '✓ PASS' : '✗ FAIL';
echo "\n";

// ════════════════════════════════════════════════════════════
// Summary
// ════════════════════════════════════════════════════════════
echo "========================================\n";
echo "SUMMARY OF UI/UX FIXES\n";
echo "========================================\n\n";

foreach ($issues as $code => $name) {
    $status = $results[$code] ?? '? UNKNOWN';
    echo "{$code}: {$name} ............ {$status}\n";
}

echo "\n";
$pass_count = count(array_filter($results, fn($v) => strpos($v, 'PASS') !== false));
$total_count = count($results);
echo "Overall: {$pass_count}/{$total_count} tests passed\n";

if ($pass_count === $total_count) {
    echo "\n🟢 ALL UI/UX FIXES VERIFIED ✓\n";
    exit(0);
} else {
    echo "\n🟡 SOME FIXES NEED ATTENTION\n";
    exit(1);
}
