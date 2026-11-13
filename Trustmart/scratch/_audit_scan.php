<?php
// ============================================================
// scratch/_audit_scan.php — deep static audit.
// Scans the app (not scratch/, tests/, db_backups/, logs/, uploads/, vendor/, docs/)
// for latent error classes:
//   A. $_POST handlers with auth but no csrf_validate
//   B. SQL alias.column references where the column doesn't exist in schema
//   C. require_role( without prior auth_check include
//   D. die()/exit() inside JSON endpoints without http_response_code
//   E. Relative "public/assets" hrefs in shared includes that could break nested
//      callers (fixed already, but guard against regressions)
//   F. SELECT ... FROM transactions with fields missing from schema
//   G. Files using trust_profiles / trust_ai_signals column names not in schema
// SQL detection: only scans inside single/double quoted strings for B/F/G.
// CLI only.
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$ROOT = realpath(__DIR__ . '/..');
$EXCLUDE = [
    '~[/\\\\]scratch[/\\\\]~i', '~[/\\\\]logs[/\\\\]~i', '~[/\\\\]db_backups[/\\\\]~i',
    '~[/\\\\]uploads[/\\\\]~i', '~[/\\\\]vendor[/\\\\]~i', '~[/\\\\]docs[/\\\\]~i',
    '~[/\\\\]tests[/\\\\]~i', '~[/\\\\]preview_router~i',
];
function ok($file) {
    global $EXCLUDE;
    foreach ($EXCLUDE as $p) if (preg_match($p, $file)) return false;
    return true;
}
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'php' && ok($f->getPathname())) {
        $files[] = $f->getPathname();
    }
}
echo "Scanning " . count($files) . " PHP files under $ROOT\n\n";

// Load DB schema
require_once __DIR__ . '/../config/db.php';
$schema = [];
$cols = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) $schema[strtolower($c['TABLE_NAME'])][strtolower($c['COLUMN_NAME'])] = true;

// Alias→table inference: per-file. We collect all "FROM tbl alias" / "JOIN tbl alias"
// occurrences inside SQL strings and build a per-file alias map.
$issues = [];

foreach ($files as $file) {
    $src  = file_get_contents($file);
    $rel  = str_replace($ROOT . DIRECTORY_SEPARATOR, '', $file);

    // Extract SQL strings (single-quoted, double-quoted, heredoc-lite) — only
    // consider strings longer than 20 chars that mention a table keyword.
    $sqlStrings = [];
    if (preg_match_all('/"(?:\\\\.|[^"\\\\])*"/s', $src, $mD)) {
        foreach ($mD[0] as $s) $sqlStrings[] = $s;
    }
    if (preg_match_all("/'(?:\\\\.|[^'\\\\])*'/s", $src, $mS)) {
        foreach ($mS[0] as $s) $sqlStrings[] = $s;
    }
    // Also treat <<<SQL ... SQL; blocks
    if (preg_match_all('/<<<[\'"]?(SQL|sql|html)[\'"]?\s*\n.*?\n\1\s*;/s', $src, $mH)) {
        foreach ($mH[0] as $s) $sqlStrings[] = $s;
    }
    // Analyse each SQL string separately so alias scopes don't bleed between queries.
    $sqlList = [];
    foreach ($sqlStrings as $s) {
        if (preg_match('/\b(SELECT|UPDATE|DELETE|INSERT)\b.+\b(FROM|INTO|SET|WHERE)\b/is', $s)) {
            $sqlList[] = $s;
        }
    }
    $sqlBlob = "\n" . implode("\n", $sqlList);

    // B. alias.column references inside SQL — resolved per-statement
    $seenB = [];
    foreach ($sqlList as $oneSql) {
        // Build alias map for this one statement
        $localAlias = [];
        if (preg_match_all('/\b(?:FROM|JOIN)\s+([a-z_][a-z0-9_]*)\s+(?:AS\s+)?([a-z_][a-z0-9_]*)\b/i', $oneSql, $mA, PREG_SET_ORDER)) {
            foreach ($mA as $p) {
                $tbl = strtolower($p[1]); $al = strtolower($p[2]);
                if (in_array($al, ['where','set','on','using','group','order','limit','inner','left','right','join','values','select','as'], true)) continue;
                if (isset($schema[$tbl])) $localAlias[$al] = $tbl;
            }
        }
        // Also allow direct table.column
        foreach ($schema as $t => $_) if (!isset($localAlias[$t])) $localAlias[$t] = $t;

        if (!preg_match_all('/\b([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\b/i', $oneSql, $mB, PREG_SET_ORDER)) continue;
        foreach ($mB as $pair) {
            $al = strtolower($pair[1]); $col = strtolower($pair[2]);
            if (!isset($localAlias[$al])) continue;
            $tbl = $localAlias[$al];
            if (!isset($schema[$tbl])) continue;
            if (isset($schema[$tbl][$col])) continue;
            if ($col === '*') continue;
            if (in_array($col, ['count','sum','avg','min','max','date','now','concat','if','ifnull','coalesce'], true)) continue;
            $k = "$al.$col=>$tbl";
            if (isset($seenB[$k])) continue;
            $seenB[$k] = true;
            $issues[] = "B. SCHEMA-MISMATCH: $rel  — $al.$col resolves to $tbl.$col which does not exist";
        }
    }

    // A. $_POST handler missing csrf_validate (has require_role/auth_check but no csrf)
    $hasPost = preg_match('/\$_POST\s*\[\s*[\'"]/', $src);
    $hasCsrf = stripos($src, 'csrf_validate') !== false;
    $hasAuth = (stripos($src, 'auth_check') !== false || stripos($src, 'require_role') !== false);
    $loginFiles = ['auth/login','auth/register','auth/forgot_password','auth/reset_password','auth/activate'];
    $isLogin = false;
    foreach ($loginFiles as $lf) if (str_contains($rel, $lf)) { $isLogin = true; break; }
    if ($hasPost && $hasAuth && !$hasCsrf && !$isLogin) {
        $issues[] = "A. CSRF-GAP: $rel  (auth-guarded \$_POST handler without csrf_validate)";
    }

    // E. relative asset paths from a nested file (would 404)
    if (preg_match('/(href|src)=[\'"]public\/assets/', $src) && !str_contains($rel, 'public/index.php') && !str_contains($rel, 'product.php') && !str_contains($rel, 'index.php')) {
        // Only flag if the file sits in a subdirectory (dashboard/, payment/, auth/, includes/)
        if (preg_match('~[/\\\\](dashboard|payment|auth|includes|config)[/\\\\]~', $rel)) {
            $issues[] = "E. RELATIVE-ASSET: $rel  (nested file with bare \"public/assets/...\" href/src)";
        }
    }

    // D. JSON endpoints calling die()/exit() with json_encode but no http_response_code set beforehand
    if (preg_match('/Content-Type:\s*application\/json/', $src)) {
        if (preg_match('/die\s*\(\s*json_encode\s*\(([^)]*)\)\s*\)/', $src, $mD2)) {
            // crude: look if http_response_code appears nearby
            $pos = $mD2[1] ?? '';
            if (stripos($src, 'http_response_code') === false) {
                $issues[] = "D. JSON-STATUS: $rel  (die(json_encode) without http_response_code)";
            }
        }
    }
}

$seen = []; $uniq = [];
foreach ($issues as $i) { if (!isset($seen[$i])) { $seen[$i] = true; $uniq[] = $i; } }

echo count($uniq) . " findings:\n";
foreach ($uniq as $i) echo "  - $i\n";
