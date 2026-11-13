<?php
// ============================================================
// tests/uat/run_all.php — runs every role's UAT spec and prints a roll-up.
//   Usage: php tests/uat/run_all.php [base-url]
// Each spec is a standalone PHP-CLI process (own exit code). This runner
// does NOT touch application code; it only executes the specs.
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$base = $argv[1] ?? 'http://localhost:8080';
$php  = PHP_BINARY; // the running PHP executable
$dir  = __DIR__;

$specs = [
    'PEMBELI'   => "$dir/pembeli/uat_pembeli.php",
    'PENJUAL'   => "$dir/penjual/uat_penjual.php",
    'PENTADBIR' => "$dir/pentadbir/uat_pentadbir.php",
];

$overall = 0;
$lines = [];
foreach ($specs as $role => $file) {
    echo "\n########## $role ##########\n";
    $out = []; $code = 0;
    exec(escapeshellarg($php) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($base) . ' 2>&1', $out, $code);
    echo implode("\n", $out) . "\n";
    $overall = max($overall, $code);
    // capture the "X PASS / Y FAIL" line
    $res = '';
    foreach ($out as $l) if (stripos($l, 'RESULT:') !== false) $res = trim($l);
    $lines[] = sprintf("%-10s exit=%d  %s", $role, $code, $res);
}

echo "\n================= UAT ROLL-UP =================\n";
foreach ($lines as $l) echo $l . "\n";
echo $overall === 0 ? "\nALL ROLE SUITES PASSED\n" : "\nSOME SUITES FAILED\n";
exit($overall);
