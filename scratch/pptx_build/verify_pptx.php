<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$path = $argv[1] ?? '';
if (!$path || !is_file($path)) { fwrite(STDERR, "usage: php verify_pptx.php <file.pptx>\n"); exit(1); }
$z = new ZipArchive();
if ($z->open($path) !== true) { fwrite(STDERR, "cannot open zip\n"); exit(1); }
$bad = 0; $names = [];
for ($i = 0; $i < $z->numFiles; $i++) {
    $n = $z->getNameIndex($i);
    $names[] = $n;
    if (substr($n, -4) !== '.xml' && substr($n, -5) !== '.rels') continue;
    $x = $z->getFromIndex($i);
    $prev = libxml_use_internal_errors(true);
    $r = simplexml_load_string($x);
    $errs = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($r === false || $errs) {
        $bad++;
        fwrite(STDOUT, 'BAD ' . $n . PHP_EOL);
        foreach ($errs as $e) { fwrite(STDOUT, '   ' . trim($e->message) . PHP_EOL); }
    }
}
$slides = 0; $notes = 0;
foreach ($names as $n) {
    if (preg_match('#^ppt/slides/slide\d+\.xml$#', $n)) $slides++;
    if (preg_match('#^ppt/notesSlides/notesSlide\d+\.xml$#', $n)) $notes++;
}
fwrite(STDOUT, sprintf("files=%d slides=%d notes=%d bad=%d\n", $z->numFiles, $slides, $notes, $bad));
$ct = $z->getFromName('[Content_Types].xml');
for ($i = 1; $i <= $slides; $i++) {
    $rels = $z->getFromName("ppt/slides/_rels/slide{$i}.xml.rels");
    if (!$rels) { fwrite(STDOUT, "MISSING rels slide{$i}\n"); $bad++; continue; }
    if (strpos($rels, 'notesSlide' . $i . '.xml') === false) { fwrite(STDOUT, "slide{$i}: no notes rel\n"); $bad++; }
    if (strpos($ct, "/ppt/slides/slide{$i}.xml") === false) { fwrite(STDOUT, "slide{$i}: no content-type\n"); $bad++; }
}
$dom = new DOMDocument();
$dom->loadXML($z->getFromName('ppt/presentation.xml'));
$cnt = $dom->getElementsByTagName('sldId')->length;
fwrite(STDOUT, "presentation sldId count = {$cnt}\n");
// dump text of each slide for content sanity
for ($i = 1; $i <= $slides; $i++) {
    $d = new DOMDocument();
    @$d->loadXML($z->getFromName("ppt/slides/slide{$i}.xml"));
    $txt = [];
    foreach ($d->getElementsByTagName('t') as $t) { $txt[] = $t->textContent; }
    // geometry audit: every xfrm must sit inside 1280x720 px canvas
    $oob = [];
    foreach ($d->getElementsByTagName('ext') as $k => $e) {
        $off = $d->getElementsByTagName('off')->item($k);
        if (!$off) continue;
        $x = (int)$off->getAttribute('x') / 9525; $y = (int)$off->getAttribute('y') / 9525;
        $w = (int)$e->getAttribute('cx') / 9525; $h = (int)$e->getAttribute('cy') / 9525;
        if ($x < 0 || $y < 0 || $x + $w > 1280.5 || $y + $h > 720.5) {
            $oob[] = sprintf('(%d,%d %dx%d)', round($x), round($y), round($w), round($h));
        }
    }
    fwrite(STDOUT, "--- slide {$i} (" . $d->getElementsByTagName('sp')->length . " shapes" . ($oob ? ", OOB: " . implode(' ', $oob) : ", in-bounds") . ") ---\n  " . implode(' | ', $txt) . "\n");
    if ($oob) $bad++;
}
$z->close();
fwrite(STDOUT, ($bad === 0 ? "OK\n" : "PROBLEMS={$bad}\n"));
