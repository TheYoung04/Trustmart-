<?php
/**
 * scratch/pptx_build/renumber_slides.php — one-shot, reviewable renumber.
 *
 * build_deck.php bakes its page number into every chrome() call and into the
 * "/* N — Title *​/" section comment, so inserting a slide mid-deck means shifting
 * every later number. Doing that by hand is 18 near-identical edits and invites
 * a silent off-by-one. This script shifts exactly two patterns, prints every line
 * it touches, and refuses to write if it matched anything unexpected.
 *
 * Usage:
 *   php renumber_slides.php            # dry run (default) — prints the plan
 *   php renumber_slides.php --apply    # writes the file
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only.\n"); }

$file    = __DIR__ . '/build_deck.php';
$from    = 4;    // first number to shift
$shift   = 1;    // how much to shift by
$apply   = in_array('--apply', $argv, true);

$src  = file_get_contents($file);
$lines = explode("\n", $src);
$plan = [];
$out  = $lines;

foreach ($lines as $i => $line) {
    $n = $i + 1;

    // 1. chrome('KICKER', 'Title', <PAGE>, $TOTAL)
    if (preg_match('/^(.*chrome\(.*,\s*)(\d+)(,\s*\$TOTAL\).*)$/', $line, $m)) {
        $page = (int) $m[2];
        if ($page >= $from) {
            $out[$i] = $m[1] . ($page + $shift) . $m[3];
            $plan[] = sprintf("L%-4d chrome  page %d -> %d", $n, $page, $page + $shift);
        } else {
            $plan[] = sprintf("L%-4d chrome  page %d (unchanged, < %d)", $n, $page, $from);
        }
        continue;
    }

    // 2. /* N — Section label */
    if (preg_match('/^\/\* (\d+) — (.*)$/', $line, $m)) {
        $num = (int) $m[1];
        if ($num >= $from) {
            $out[$i] = '/* ' . ($num + $shift) . ' — ' . $m[2];
            $plan[] = sprintf("L%-4d comment  %d -> %d", $n, $num, $num + $shift);
        } else {
            $plan[] = sprintf("L%-4d comment  %d (unchanged)", $n, $num);
        }
        continue;
    }
}

// Sanity: the cover and the closing slide are hand-built and carry no chrome(),
// so the expected relationship is chrome = comments - 2, not equality.
$chromeCount = count(array_filter($lines, fn($l) => str_contains($l, 'chrome(')));
$commentCount = count(array_filter($lines, fn($l) => (bool) preg_match('/^\/\* \d+ — /', $l)));
echo "chrome() lines found: $chromeCount   section comments found: $commentCount\n";
echo implode("\n", $plan) . "\n";

if ($commentCount < 2 || $chromeCount !== $commentCount - 2) {
    fwrite(STDERR, "\nABORT: expected chrome() count to be exactly 2 fewer than the section-comment count"
        . " (cover + closing have no chrome). Got $chromeCount vs $commentCount.\n");
    exit(1);
}

echo "\n" . ($apply ? "APPLYING...\n" : "DRY RUN — nothing written. Use --apply.\n");
if ($apply) {
    file_put_contents($file, implode("\n", $out));
    echo "written: $file\n";
}
