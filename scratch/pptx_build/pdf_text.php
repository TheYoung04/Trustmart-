<?php
// scratch/pdf_text.php - CLI-only crude PDF text extractor (inflates FlateDecode
// content streams and pulls literal strings from Tj/TJ text operators).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$file = $argv[1] ?? '';
if (!$file || !is_file($file)) { fwrite(STDERR, "usage: php pdf_text.php <file.pdf>\n"); exit(1); }
$bytes = file_get_contents($file);

// Unescape a PDF literal string body.
function pdf_unescape(string $s): string {
    $out = ''; $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c === '\\' && $i + 1 < $len) {
            $n = $s[++$i];
            if ($n === 'n') $out .= "\n";
            elseif ($n === 'r') $out .= "\r";
            elseif ($n === 't') $out .= "\t";
            elseif ($n === 'b') $out .= "\x08";
            elseif ($n === 'f') $out .= "\x0C";
            elseif ($n === '\\') $out .= '\\';
            elseif ($n === '(') $out .= '(';
            elseif ($n === ')') $out .= ')';
            elseif ($n >= '0' && $n <= '7') {
                $oct = $n;
                for ($k = 0; $k < 2 && $i + 1 < $len && $s[$i+1] >= '0' && $s[$i+1] <= '7'; $k++) { $oct .= $s[++$i]; }
                $out .= chr(octdec($oct));
            } else { $out .= $n; }
        } else { $out .= $c; }
    }
    return $out;
}

// Collect all stream payloads; try to inflate; extract text operators.
preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $m);
$chunks = $m[1] ?? [];
$text = '';
foreach ($chunks as $raw) {
    $data = @gzuncompress($raw);
    if ($data === false) { $data = @gzinflate($raw); }
    if ($data === false) { $data = @gzinflate(substr($raw, 2)); }
    $candidate = ($data !== false) ? $data : $raw;
    // Only treat as content if it looks like PDF text operators.
    if (!preg_match('/\bTj\b|\bTJ\b|\bTd\b|\bTf\b/', $candidate)) { continue; }
    // Grab literal strings ( ... ) Tj  and ( ... ) within [ ... ] TJ
    if (preg_match_all('/\(((?:\\.|[^\\()])*)\)\s*Tj/s', $candidate, $tj)) {
        foreach ($tj[1] as $s) { $text .= pdf_unescape($s); }
        $text .= "\n";
    }
    if (preg_match_all('/\[((?:[^\[\]]|\\.)*?)\]\s*TJ/s', $candidate, $tja)) {
        foreach ($tja[1] as $arr) {
            if (preg_match_all('/\(((?:\\.|[^\\()])*)\)/s', $arr, $parts)) {
                foreach ($parts[1] as $s) { $text .= pdf_unescape($s); }
            }
        }
        $text .= "\n";
    }
}
// Normalise whitespace a bit
$text = preg_replace('/[ \t]+/', ' ', $text);
$text = preg_replace('/\n{3,}/', "\n\n", $text);
$out = trim($text);
fwrite(STDOUT, $out === '' ? "(no extractable text layer - likely scanned images)\n" : $out . "\n");
