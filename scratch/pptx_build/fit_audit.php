<?php
/**
 * scratch/pptx_build/fit_audit.php — estimate text overflow inside each shape.
 *
 * verify_pptx.php proves every shape sits inside the 1280x720 canvas, but it is
 * blind to the failure that actually bites when you hand-tune pixel geometry:
 * copy that is too long for its own box. The helpers emit <a:noAutofit/>, so
 * PowerPoint neither shrinks nor grows the text — it just clips or spills.
 * With no LibreOffice/PowerPoint on this machine there is no real render, so
 * this is a documented estimate, not visual approval.
 *
 * Model (deliberately conservative):
 *   char advance = 0.66 * pt  (px)  — Calibri averages ~0.48em, 1pt = 1.333px,
 *                                    inflated to ~0.66 to cover wide glyphs/accents
 *   line height  = lsPct/100 * 1.25 * pt * 1.333
 *   usable width = shape w - 9.6px (lIns/rIns 45720 EMU each)
 *   usable height= shape h - 5.8px (tIns/bIns 27432 EMU each)
 *
 * Usage: php -d extension=zip fit_audit.php <file.pptx> [slideNumber]
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only.\n"); }
$path = $argv[1] ?? '';
$only = isset($argv[2]) ? (int) $argv[2] : 0;
if (!$path || !is_file($path)) { fwrite(STDERR, "usage: php fit_audit.php <file.pptx> [slide]\n"); exit(1); }

$z = new ZipArchive();
if ($z->open($path) !== true) { fwrite(STDERR, "cannot open zip\n"); exit(1); }

$CHAR_PX_PER_PT = 0.66;
$LINE_MULT      = 1.25;
$PT_TO_PX       = 1.3333;
$INS_X          = 9.6;
$INS_Y          = 5.8;

$worst = [];
$total = 0;
for ($i = 1; $i <= $z->numFiles; $i++) {
    $name = $z->getNameIndex($i);
    if (!preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) continue;
    $slideNo = (int) $m[1];
    if ($only && $slideNo !== $only) continue;

    $dom = new DOMDocument();
    $dom->loadXML($z->getFromName($name));
    foreach ($dom->getElementsByTagName('sp') as $sp) {
        $xfrm = $sp->getElementsByTagName('xfrm')->item(0);
        if (!$xfrm) continue;
        $off = $xfrm->getElementsByTagName('off')->item(0);
        $ext = $xfrm->getElementsByTagName('ext')->item(0);
        if (!$off || !$ext) continue;
        $w = (int) $ext->getAttribute('cx') / 9525;
        $h = (int) $ext->getAttribute('cy') / 9525;
        $usableW = max(1, $w - $INS_X);
        $usableH = max(1, $h - $INS_Y);

        $need = 0.0;
        $label = '';
        foreach ($sp->getElementsByTagName('p') as $p) {
            $text = '';
            $maxPt = 0;
            $ls = 118;
            foreach ($p->getElementsByTagName('r') as $r) {
                $t = $r->getElementsByTagName('t')->item(0);
                if ($t) $text .= $t->textContent;
                $rPr = $r->getElementsByTagName('rPr')->item(0);
                if ($rPr) $maxPt = max($maxPt, (int) $rPr->getAttribute('sz') / 100);
            }
            $pPr = $p->getElementsByTagName('pPr')->item(0);
            $afterPx = 0;
            if ($pPr) {
                $ln = $pPr->getElementsByTagName('lnSpc')->item(0);
                if ($ln) {
                    $pct = $ln->getElementsByTagName('spcPct')->item(0);
                    if ($pct) $ls = (int) $pct->getAttribute('val') / 1000;
                }
                $aft = $pPr->getElementsByTagName('spcAft')->item(0);
                if ($aft) {
                    $pts = $aft->getElementsByTagName('spcPts')->item(0);
                    if ($pts) $afterPx = ((int) $pts->getAttribute('val') / 100) * $PT_TO_PX;
                }
            }
            if ($text === '' || $maxPt === 0) continue;
            if ($label === '') $label = mb_substr($text, 0, 42);
            $linePx  = ($ls / 100) * $LINE_MULT * $maxPt * $PT_TO_PX;
            // mb_strlen, not strlen: the deck uses em dashes and middots, which are
            // 2-3 UTF-8 bytes each and would be counted as 2-3 characters.
            $lineW   = mb_strlen($text) * $CHAR_PX_PER_PT * $maxPt;
            $lines   = max(1, (int) ceil($lineW / $usableW));
            // space-after belongs to the paragraph that declares it and applies
            // even on its final line — omitting it under-reports every card.
            $need   += $lines * $linePx + $afterPx;
        }
        if ($need <= 0) continue;
        $total++;
        // Only a shape with its own fill makes overflow VISIBLE — a borderless
        // text box just spills into empty space, which is how every chrome()
        // title on this deck already "overflows" harmlessly. Classify so the
        // report shows real spills instead of 90% geometry noise.
        $spPr = $sp->getElementsByTagName('spPr')->item(0);
        $filled = $spPr && $spPr->getElementsByTagName('solidFill')->item(0);
        if ($need > $usableH) {
            $row = sprintf('%-6s %dx%-5d needs ~%dpx  has %dpx   "%s"',
                $filled ? 'CARD' : 'tbox', round($w), round($h), round($need), round($usableH), $label);
            $worst[] = [$filled, $slideNo, $row];
        }
    }
}

// Cards first — those are the ones that actually look broken.
usort($worst, fn($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
$cards = array_filter($worst, fn($r) => $r[0]);
echo "checked $total text-bearing shapes\n";
if (!$worst) { echo "FIT OK — no shape estimated to overflow its own box\n"; }
else {
    echo count($cards) . " VISIBLE SPILL (filled card, fix these):\n";
    foreach ($worst as $r) echo "  slide {$r[1]}  {$r[2]}\n";
    if (!$cards) echo "  (none — every overflow above is a borderless text box; spill is invisible)\n";
}
$z->close();
exit($cards ? 1 : 0);
