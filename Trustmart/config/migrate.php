<?php
// ============================================================
// config/migrate.php
// Ordered, idempotent database migration runner for TrustMart.
//
// The base schema lives in ../trustmart.sql. Incremental changes are
// the config/schema_update*.sql files (schema_update.sql is treated as v1,
// then v2..vN in ascending numeric order). They are applied with the MySQL
// CLI — NOT PDO — because many of them contain DELIMITER blocks for stored
// procedures/triggers that PDO::exec() cannot parse. Every file is written to
// be idempotent (IF NOT EXISTS / DROP ... IF EXISTS), so re-running is safe.
//
// USAGE (CLI only):
//   php config/migrate.php                # apply ALL pending migrations in order
//   php config/migrate.php --only=11      # apply just v11 (also accepts 2,5,11)
//   php config/migrate.php --from=6       # apply v6 .. latest
//   php config/migrate.php --dry-run      # just show the ordered plan
//
// This is a local/XAMPP maintenance tool and refuses to run outside CLI.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("migrate.php must be run from the command line (php config/migrate.php).\n");
}

require_once __DIR__ . '/db.php'; // provides DB_HOST / DB_NAME / DB_USER / DB_PASS

// ── Parse CLI options ─────────────────────────────────────────
$argvv = $argv ?? [];
$only  = null;   // array of explicit versions
$from  = null;   // start version
$dry   = in_array('--dry-run', $argvv, true);
foreach ($argvv as $a) {
    if (strpos($a, '--only=') === 0) {
        $only = array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', substr($a, 7)))));
    } elseif (strpos($a, '--from=') === 0) {
        $from = (int) substr($a, 7);
    }
}

// ── Discover + order migration files ──────────────────────────
// schema_update.sql => 1 ; schema_update_vN.sql => N
$files = glob(__DIR__ . '/schema_update*.sql') ?: [];
$plan = [];
foreach ($files as $f) {
    $base = basename($f);
    if ($base === 'schema_update.sql') {
        $ver = 1;
    } elseif (preg_match('/schema_update_v(\d+)\.sql$/i', $base, $m)) {
        $ver = (int) $m[1];
    } else {
        continue; // not a migration file
    }
    $plan[$ver] = $f;
}
ksort($plan);

// Apply selection filters.
$selected = $plan;
if ($only !== null) {
    $selected = array_intersect_key($plan, array_flip($only));
} elseif ($from !== null) {
    $selected = array_filter($plan, fn($v) => $v >= $from, ARRAY_FILTER_USE_KEY);
}

if (!$selected) {
    fwrite(STDERR, "No matching migrations.\n");
    exit(2);
}

// ── Locate the MySQL CLI binary ───────────────────────────────
$mysql = null;
foreach (['C:\\xampp\\mysql\\bin\\mysql.exe', 'C:\\xampp\\mysql\\bin\\mysql', '/usr/bin/mysql', 'mysql'] as $c) {
    if ($c === 'mysql') { // PATH fallback: verify it is runnable
        exec('mysql --version 2>&1', $o, $rc);
        if ($rc === 0) { $mysql = 'mysql'; break; }
    } elseif (is_file($c)) {
        $mysql = $c; break;
    }
}
if (!$mysql) {
    fwrite(STDERR, "Could not find the mysql client binary. Set it up or pass it explicitly.\n");
    exit(3);
}

// ── Build the base CLI arguments from DB_* config ─────────────
$argstr = '--host=' . escapeshellarg(DB_HOST)
        . ' --user=' . escapeshellarg(DB_USER)
        . ' --database=' . escapeshellarg(DB_NAME)
        . ' --default-character-set=utf8mb4';
if (DB_PASS !== '') {
    $argstr .= ' --password=' . escapeshellarg(DB_PASS);
}

echo "DB: " . DB_USER . '@' . DB_HOST . '/' . DB_NAME . "\n";
echo "Client: $mysql\n";
echo $dry ? "DRY RUN — planned order:\n" : "Applying migrations:\n";

$applied = 0; $errors = 0;
foreach ($selected as $ver => $file) {
    $label = "v$ver  " . basename($file);
    if ($dry) {
        echo "  [PLAN]  $label\n";
        continue;
    }
    echo "  -> $label ... ";
    // Feed the file via stdin so DELIMITER blocks are handled by the CLI.
    $cmd = escapeshellarg($mysql) . ' ' . $argstr . ' < ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $out, $rc);
    if ($rc === 0) {
        echo "OK\n";
        $applied++;
    } else {
        echo "ERROR (exit $rc)\n";
        foreach (array_slice($out, 0, 6) as $line) {
            echo "       " . $line . "\n";
        }
        $errors++;
    }
    $out = [];
}

echo "\n";
if ($dry) {
    echo count($selected) . " migration(s) planned (v" . min(array_keys($selected)) . "..v" . max(array_keys($selected)) . ").\n";
    exit(0);
}
echo "Applied: $applied, Errors: $errors.\n";
exit($errors === 0 ? 0 : 1);
