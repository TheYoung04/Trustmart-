<?php
/**
 * scratch/db_restore_dump.php — restore a SQL dump into the configured database.
 *
 * Why this exists rather than `mysql < file.sql`:
 *   1. Two of the three dumps in db_backups/ were written with a PowerShell `>`
 *      redirect, which in 5.1 emits UTF-16LE + BOM. mysql reads that as garbage
 *      (or imports nothing at all), so the backup LOOKS fine in Explorer and
 *      silently cannot restore. This detects it and converts to a UTF-8 sidecar
 *      instead of failing.
 *   2. The dumps contain DELIMITER blocks for triggers and procedures, so they
 *      must go through the mysql CLI — not PDO. config/migrate.php already has
 *      to do this for the same reason; the shell can't be reached from
 *      PowerShell, which has no `<` redirection at all.
 *   3. Restoring drops tables. It therefore refuses to run without --force and
 *      prints what it is about to do first.
 *
 * A restore replaces the CURRENT data. Take a safety dump beforehand:
 *   C:\xampp\mysql\bin\mysqldump.exe --user=root --single-transaction
 *       --routines --triggers --result-file=db_backups\safety.sql trustmart
 * (use --result-file, never `>`, or you create the problem this tool works around.)
 *
 * Run:
 *   C:\xampp\php\php.exe scratch/db_restore_dump.php <file.sql> --dry-run
 *   C:\xampp\php\php.exe scratch/db_restore_dump.php <file.sql> --force
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only.\n"); }
require_once __DIR__ . '/../config/db.php';

$file  = $argv[1] ?? '';
$dry   = in_array('--dry-run', $argv, true);
$force = in_array('--force', $argv, true);

if ($file === '' || strpos($file, '--') === 0) {
    echo " guna: php scratch/db_restore_dump.php <fail.sql> [--dry-run|--force]\n";
    exit(2);
}
if (!is_file($file)) { echo " tiada fail: $file\n"; exit(2); }

$txt = (string) file_get_contents($file);
$bom = substr($txt, 0, 2);
$utf16 = ($bom === "\xFF\xFE" || $bom === "\xFE\xFF" || substr_count(substr($txt, 0, 400), "\0") > 20);
if ($utf16) {
    if ($bom === "\xFF\xFE" || $bom === "\xFE\xFF") $txt = substr($txt, 2);
    $txt = str_replace("\0", '', $txt);
    if (!mb_check_encoding($txt, 'UTF-8')) {
        echo " gagal: selepas buang NUL kandungan masih bukan UTF-8 — jangan import.\n";
        exit(1);
    }
    echo " amaran: dump ini UTF-16 (dijalankan dengan `>` dalam PowerShell)."
       . " Ditukar ke UTF-8 untuk diimport.\n";
}
$txt = str_replace(["\r\n", "\r"], "\n", $txt);

$target = DB_NAME;
printf(" sumber : %s (%d bytes)\n sasaran: %s@%s/%s\n",
    basename($file), strlen($txt), DB_USER, DB_HOST, $target);
foreach (['CREATE DATABASE', 'DROP TABLE', 'INSERT INTO', 'DELIMITER'] as $n) {
    printf("   %-15s %d\n", $n . ':', substr_count(strtoupper($txt), $n));
}
if (stripos($txt, 'DROP TABLE') === false && stripos($txt, 'CREATE TABLE') === false
    && stripos($txt, 'INSERT INTO') === false) {
    echo " berhenti: fail ini tidak mengandungi SQL yang difahami.\n";
    exit(1);
}
// The dump must not aim at a different schema than the app is configured for.
if (preg_match_all('/CREATE DATABASE.*?`(\w+)`/i', $txt, $dm)) {
    foreach (array_unique($dm[1]) as $db) {
        if ($db !== $target) {
            echo " amaran: dump ini merujuk ke schema `$db`, aplikasi pakai `$target`."
               . " Import akan diarahkan ke $target melalui --database, tapi periksa dulu.\n";
        }
    }
}

if ($dry) { echo "\n DRY RUN — tiada apa ditulis.\n"; exit(0); }
if (!$force) {
    echo "\n BERBAHAYA: import akan menggantikan data semasa dalam `$target`"
       . " (dump ini ada DROP TABLE/CREATE TABLE).\n"
       . " Buat safety dump dulu, kemudian jalankan dengan --force.\n";
    exit(2);
}

// Write the LF/UTF-8 copy next to the original so repeated restores are exact.
$tmp = sys_get_temp_dir() . '/trustmart-restore-' . getmypid() . '.sql';
file_put_contents($tmp, $txt);

$mysql = 'C:\\xampp\\mysql\\bin\\mysql.exe';
if (!is_file($mysql)) { $mysql = 'mysql'; }
$args = '--host=' . escapeshellarg(DB_HOST) . ' --user=' . escapeshellarg(DB_USER)
      . ' --database=' . escapeshellarg($target) . ' --default-character-set=utf8mb4';
if (DB_PASS !== '') $args .= ' --password=' . escapeshellarg(DB_PASS);

echo "\n mengimport ...\n";
exec(escapeshellarg($mysql) . ' ' . $args . ' < ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
@unlink($tmp);
foreach ($out as $l) { echo "   mysql: $l\n"; }
if ($rc !== 0) { echo " GAGAL (exit $rc) — data semasa mungkin separuh dipulihkan."
                . " Pulihkan daripada safety dump dan semak semula.\n"; exit(1); }

echo "\n-- kiraan baris selepas import --\n";
foreach (['users','products','transactions','reviews','disputes','payments','cash_payments',
          'trust_profiles','messages'] as $t) {
    try { printf("   %-16s %s\n", $t, $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()); }
    catch (PDOException $e) { printf("   %-16s (tiada jadual)\n", $t); }
}
echo "\n OK. Langkah seterusnya: php config/migrate.php   (naikkan skema ke versi terkini)\n";
exit(0);
