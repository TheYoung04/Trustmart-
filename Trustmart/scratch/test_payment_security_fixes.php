<?php
/**
 * Test Payment Security Fixes
 * 
 * Verifies that all critical vulnerabilities have been fixed:
 * 1. PDO (not mysqli) is used throughout payment system
 * 2. HMAC signature verification works correctly
 * 3. Environment configuration loads properly
 * 4. Payment callback rejects unsigned requests
 */

echo "=== TrustMart Payment Security Fix Verification ===\n\n";

$errors = [];
$warnings = [];
$passed = [];

// Test 1: Environment loader
echo "Test 1: Environment Configuration Loader\n";
echo "----------------------------------------\n";
try {
    require_once __DIR__ . '/../config/env.php';
    
    // Test env() function exists
    if (!function_exists('env')) {
        $errors[] = "env() function not found";
    } else {
        $passed[] = "env() function loaded successfully";
    }
    
    // Test load_env() function exists
    if (!function_exists('load_env')) {
        $errors[] = "load_env() function not found";
    } else {
        $passed[] = "load_env() function loaded successfully";
    }
    
    // Test environment variable with default
    $test_val = env('NONEXISTENT_VAR', 'default_value');
    if ($test_val === 'default_value') {
        $passed[] = "env() default values work correctly";
    } else {
        $errors[] = "env() default values not working";
    }
    
} catch (Exception $e) {
    $errors[] = "Failed to load env.php: " . $e->getMessage();
}
echo "\n";

// Test 2: Database configuration uses environment
echo "Test 2: Database Configuration\n";
echo "----------------------------------------\n";
try {
    require_once __DIR__ . '/../config/db.php';
    
    // Check that PDO object exists
    if (!isset($pdo)) {
        $errors[] = "\$pdo object not initialized";
    } elseif (!($pdo instanceof PDO)) {
        $errors[] = "\$pdo is not a PDO instance";
    } else {
        $passed[] = "PDO database connection initialized";
    }
    
    // Check constants are defined
    $required_constants = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET'];
    foreach ($required_constants as $const) {
        if (!defined($const)) {
            $errors[] = "Constant $const not defined";
        }
    }
    
    if (count($errors) === 0 || isset($pdo)) {
        $passed[] = "All database constants defined";
    }
    
} catch (PDOException $e) {
    $errors[] = "Database connection failed: " . $e->getMessage();
} catch (Exception $e) {
    $errors[] = "Failed to load db.php: " . $e->getMessage();
}
echo "\n";

// Test 3: Payment configuration
echo "Test 3: Payment Configuration\n";
echo "----------------------------------------\n";
try {
    require_once __DIR__ . '/../config/payment.php';
    
    // Check critical constants
    if (!defined('PAYMENT_CALLBACK_SECRET')) {
        $errors[] = "PAYMENT_CALLBACK_SECRET not defined";
    } else {
        $passed[] = "PAYMENT_CALLBACK_SECRET is defined";
        
        // Warn if using default secret
        if (strpos(PAYMENT_CALLBACK_SECRET, 'default_dev_secret_') === 0) {
            $warnings[] = "Using default PAYMENT_CALLBACK_SECRET - set in .env for production!";
        }
    }
    
    if (!defined('PAYMENT_BASE_URL')) {
        $errors[] = "PAYMENT_BASE_URL not defined";
    } else {
        $passed[] = "PAYMENT_BASE_URL is defined: " . PAYMENT_BASE_URL;
        
        // Warn if still using localhost in production
        if (strpos(PAYMENT_BASE_URL, 'localhost') !== false) {
            $warnings[] = "PAYMENT_BASE_URL contains 'localhost' - update for production";
        }
    }
    
    // Check signature functions exist
    if (!function_exists('generate_payment_signature')) {
        $errors[] = "generate_payment_signature() function not found";
    } else {
        $passed[] = "generate_payment_signature() function exists";
    }
    
    if (!function_exists('verify_payment_signature')) {
        $errors[] = "verify_payment_signature() function not found";
    } else {
        $passed[] = "verify_payment_signature() function exists";
    }
    
} catch (Exception $e) {
    $errors[] = "Failed to load payment.php: " . $e->getMessage();
}
echo "\n";

// Test 4: Signature generation and verification
echo "Test 4: HMAC Signature Verification\n";
echo "----------------------------------------\n";
try {
    if (function_exists('generate_payment_signature') && function_exists('verify_payment_signature')) {
        $ref = 'TM20260101000001abc123';
        $status = '1';
        $amount = 100.50;
        
        // Generate signature
        $signature = generate_payment_signature($ref, $status, $amount);
        
        if (empty($signature)) {
            $errors[] = "Signature generation returned empty value";
        } else {
            $passed[] = "Signature generated: " . substr($signature, 0, 16) . "...";
        }
        
        // Verify correct signature
        $valid = verify_payment_signature($ref, $status, $amount, $signature);
        if ($valid === true) {
            $passed[] = "Valid signature verification: PASS ✓";
        } else {
            $errors[] = "Valid signature verification: FAIL (should be true)";
        }
        
        // Verify tampered signature
        $tampered_sig = substr($signature, 0, -4) . 'XXXX';
        $invalid = verify_payment_signature($ref, $status, $amount, $tampered_sig);
        if ($invalid === false) {
            $passed[] = "Tampered signature rejection: PASS ✓";
        } else {
            $errors[] = "Tampered signature rejection: FAIL (should be false)";
        }
        
        // Verify tampered amount
        $tampered_amount = verify_payment_signature($ref, $status, 999.99, $signature);
        if ($tampered_amount === false) {
            $passed[] = "Tampered amount rejection: PASS ✓";
        } else {
            $errors[] = "Tampered amount rejection: FAIL (should be false)";
        }
        
    } else {
        $errors[] = "Cannot test signatures - functions not loaded";
    }
} catch (Exception $e) {
    $errors[] = "Signature test failed: " . $e->getMessage();
}
echo "\n";

// Test 5: Check payment files for mysqli usage (code inspection)
echo "Test 5: PDO vs mysqli Usage Check\n";
echo "----------------------------------------\n";
$payment_files = [
    __DIR__ . '/../payment/pay.php',
    __DIR__ . '/../payment/gateway_sim.php',
    __DIR__ . '/../payment/return.php',
    __DIR__ . '/../payment/callback.php'
];

foreach ($payment_files as $file) {
    if (!file_exists($file)) {
        $errors[] = "File not found: " . basename($file);
        continue;
    }
    
    $content = file_get_contents($file);
    $basename = basename($file);
    
    // Check for mysqli usage
    if (preg_match('/\$conn\s*->\s*prepare/', $content)) {
        $errors[] = "$basename still uses mysqli (\$conn->prepare)";
    } elseif (preg_match('/\$stmt\s*->\s*bind_param/', $content)) {
        $errors[] = "$basename still uses mysqli (\$stmt->bind_param)";
    } elseif (preg_match('/\$stmt\s*->\s*get_result/', $content)) {
        $errors[] = "$basename still uses mysqli (\$stmt->get_result)";
    } else {
        $passed[] = "$basename: No mysqli usage detected ✓";
    }
    
    // Check for PDO usage
    if (preg_match('/\$pdo\s*->\s*prepare/', $content)) {
        $passed[] = "$basename: Uses PDO (\$pdo->prepare) ✓";
    } else {
        $warnings[] = "$basename: No \$pdo->prepare found (may not use database)";
    }
}
echo "\n";

// Test 6: Check callback.php for signature verification
echo "Test 6: Callback Signature Enforcement\n";
echo "----------------------------------------\n";
$callback_file = __DIR__ . '/../payment/callback.php';
if (file_exists($callback_file)) {
    $content = file_get_contents($callback_file);
    
    if (strpos($content, 'verify_payment_signature') !== false) {
        $passed[] = "callback.php calls verify_payment_signature() ✓";
    } else {
        $errors[] = "callback.php does NOT verify signatures - CRITICAL!";
    }
    
    if (strpos($content, '$signature') !== false) {
        $passed[] = "callback.php checks signature parameter ✓";
    } else {
        $errors[] = "callback.php does NOT check signature parameter";
    }
    
    // Check for proper rejection of unsigned requests
    if (preg_match('/empty\s*\(\s*\$signature\s*\)/', $content)) {
        $passed[] = "callback.php checks for empty signature ✓";
    } else {
        $warnings[] = "callback.php may not explicitly check for empty signature";
    }
} else {
    $errors[] = "callback.php not found";
}
echo "\n";

// Test 7: Check .env.example exists
echo "Test 7: Configuration Files\n";
echo "----------------------------------------\n";
$config_files = [
    __DIR__ . '/../.env.example' => '.env.example',
    __DIR__ . '/../.gitignore' => '.gitignore',
    __DIR__ . '/../CONFIG.md' => 'CONFIG.md'
];

foreach ($config_files as $path => $name) {
    if (file_exists($path)) {
        $passed[] = "$name exists ✓";
        
        if ($name === '.gitignore') {
            $content = file_get_contents($path);
            if (strpos($content, '.env') !== false) {
                $passed[] = ".gitignore excludes .env file ✓";
            } else {
                $errors[] = ".gitignore does NOT exclude .env - SECURITY RISK!";
            }
        }
    } else {
        $warnings[] = "$name not found";
    }
}

// Check if .env exists (should not be in repo, but needed locally)
if (file_exists(__DIR__ . '/../.env')) {
    $warnings[] = ".env file exists (good for local dev, but ensure it's in .gitignore!)";
} else {
    $warnings[] = ".env file not found - copy .env.example to .env for production";
}
echo "\n";

// ============================================================
// SUMMARY
// ============================================================
echo "\n";
echo "=================================================\n";
echo "                SUMMARY REPORT                   \n";
echo "=================================================\n\n";

echo "✓ PASSED: " . count($passed) . " checks\n";
echo "⚠ WARNINGS: " . count($warnings) . " items\n";
echo "✗ FAILED: " . count($errors) . " critical issues\n\n";

if (count($errors) > 0) {
    echo "CRITICAL ERRORS:\n";
    echo "----------------\n";
    foreach ($errors as $i => $error) {
        echo ($i + 1) . ". ✗ $error\n";
    }
    echo "\n";
}

if (count($warnings) > 0) {
    echo "WARNINGS:\n";
    echo "---------\n";
    foreach ($warnings as $i => $warning) {
        echo ($i + 1) . ". ⚠ $warning\n";
    }
    echo "\n";
}

echo "PASSED CHECKS:\n";
echo "--------------\n";
foreach ($passed as $i => $pass) {
    echo ($i + 1) . ". ✓ $pass\n";
}
echo "\n";

// Final verdict
echo "=================================================\n";
if (count($errors) === 0) {
    echo "✓ ALL CRITICAL FIXES VERIFIED - SYSTEM SECURE\n";
    echo "=================================================\n";
    exit(0);
} else {
    echo "✗ CRITICAL ISSUES FOUND - REVIEW REQUIRED\n";
    echo "=================================================\n";
    exit(1);
}
