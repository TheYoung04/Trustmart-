<?php
/** Minimal PowerPoint (OOXML) builder helpers - pure PHP, no extensions besides zip. */

const EMU_PER_PX = 9525;
const SW = 1280;
const SH = 720;

// Deck palette (px colors, hex without #)
const C_BG      = 'FFFFFF';
const C_DARK    = '132A2E'; // cover background / dark text
const C_TEXT    = '1F2D32';
const C_MED     = '5B6B72';
const C_LIGHT   = 'AEBEC4';
const C_ACCENT  = '0E7C86'; // primary teal
const C_ACC_T   = 'E4F1F2'; // accent tint
const C_AMBER   = 'D97E1F'; // secondary accent
const C_AMB_T   = 'FBEEDD'; // amber tint
const C_CARD    = 'F3F6F7';
const C_BORDER  = 'DCE4E7';
const C_GREEN   = '2E9E5B';
const C_GRN_T   = 'E2F2E8';
const C_ONDARK  = 'E9F1F3';

function emu(int $v): int { return (int)round($v * EMU_PER_PX); }
function esc(string $t): string { return htmlspecialchars($t, ENT_QUOTES | ENT_XML1); }

const SHAPE_MAP = [
    'rect' => 'rect', 'roundRect' => 'roundRect', 'ellipse' => 'ellipse',
    'arrow' => 'arrowRight', 'chev' => 'chevron', 'diamond' => 'diamond', 'line' => 'line',
];

/**
 * Build a shape element: geometry + fill + stroke + optional paragraphs.
 */
function sh(string $type, float $x, float $y, float $w, float $h, $fill = null, $stroke = null, array $paras = [], string $prst = null): array {
    return ['k' => 'shape', 'type' => $type, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'fill' => $fill, 'stroke' => $stroke, 'paras' => $paras, 'prstGeom' => $prst];
}

/** Standalone text box (zero inner padding assumption for layout math). */
function tb(float $x, float $y, float $w, float $h, array $paras, string $valign = 't'): array {
    return ['k' => 'text', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'paras' => $paras, 'valign' => $valign];
}

/** Build one run. */
function run(string $text, float $sizePt, string $color = C_TEXT, bool $bold = false, bool $italic = false): array {
    return ['text' => $text, 'sizePt' => $sizePt, 'color' => $color, 'bold' => $bold, 'italic' => $italic];
}

/** Build one paragraph from runs. */
function para(array $runs, string $align = 'left', float $lineSpacingPct = 118, float $spaceAfterPt = 0, bool $bullet = false): array {
    return ['runs' => $runs, 'align' => $align, 'lsPct' => $lineSpacingPct, 'spaceAfterPt' => $spaceAfterPt, 'bullet' => $bullet];
}

function xmlBoolAttr(bool $v): string { return $v ? '1' : '0'; }

function runXml(array $r): string {
    $sz = (int)round($r['sizePt'] * 100);
    $attrs = sprintf('lang="en-US" sz="%d" b="%d" i="%d" dirty="0"', $sz, xmlBoolAttr($r['bold']), xmlBoolAttr($r['italic']));
    $out = '<a:r><a:rPr ' . $attrs . '><a:solidFill><a:srgbClr val="' . $r['color'] . '"/></a:solidFill>'
         . '<a:latin typeface="Calibri"/><a:ea typeface="Calibri"/><a:cs typeface="Calibri"/></a:rPr>'
         . '<a:t>' . esc($r['text']) . '</a:t></a:r>';
    return $out;
}

function paraXml(array $p): string {
    $al = $p['align'] === 'center' ? 'ctr' : ($p['align'] === 'right' ? 'rt' : 'l');
    $ln = (int)round($p['lsPct'] * 1000);
    $sa = (int)round($p['spaceAfterPt'] * 100);
    $bullet = $p['bullet'] ? '<a:buChar char="&#8226;"/>' : '<a:buNone/>';
    $runs = implode('', array_map('runXml', $p['runs']));
    return '<a:p><a:pPr algn="' . $al . '" marL="0" indent="0"><a:lnSpc><a:spcPct val="' . $ln . '"/></a:lnSpc>'
         . '<a:spcAft><a:spcPts val="' . $sa . '"/></a:spcAft>' . $bullet . '</a:pPr>' . $runs
         . '<a:endParaRPr lang="en-US" sz="1200"/></a:p>';
}

function txBodyXml(array $paras, float $w, float $h, string $valign): string {
    $anchor = ['t' => 't', 'ctr' => 'ctr', 'b' => 'b'][$valign] ?? 't';
    $body = '<a:bodyPr wrap="square" lIns="45720" tIns="27432" rIns="45720" bIns="27432" anchor="' . $anchor . '">'
          . '<a:noAutofit/></a:bodyPr><a:lstStyle/>';
    return $body . '<p:txBody>' . implode('', array_map('paraXml', $paras)) . '</p:txBody>';
}

function spXml(array $el, int $id): string {
    $x = emu((int)$el['x']); $y = emu((int)$el['y']); $cx = emu((int)$el['w']); $cy = emu((int)$el['h']);
    $prstGeom = $el['prstGeom'] ?? (SHAPE_MAP[$el['type']] ?? 'rect');
    // fill
    if ($el['fill'] === null) {
        $fill = '<a:noFill/>';
    } elseif ($el['fill'] === 'bg') {
        $fill = '<a:solidFill><a:srgbClr val="' . C_BG . '"/></a:solidFill>';
    } else {
        $fill = '<a:solidFill><a:srgbClr val="' . $el['fill'] . '"/></a:solidFill>';
    }
    // stroke
    if (is_array($el['stroke'])) {
        $w = (int)round(($el['stroke']['wPt'] ?? 1) * 12700);
        $dash = !empty($el['stroke']['dash']) ? '<a:prstDash val="sysDash"/>' : '';
        $stroke = '<a:ln w="' . $w . '"><a:solidFill><a:srgbClr val="' . $el['stroke']['color'] . '"/></a:solidFill>' . $dash . '</a:ln>';
    } else {
        $stroke = '<a:ln><a:noFill/></a:ln>';
    }
    $nv = sprintf('<p:nvSpPr><p:cNvPr id="%d" name="s%d"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>', $id, $id);
    if ($el['type'] === 'line') {
        $stroke = str_replace('<a:ln ', '<a:ln cap="rnd" ', $stroke);
    }
    $tx = $el['paras'] ? txBodyXml($el['paras'], $el['w'], $el['h'], 'ctr') : '<p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody>';
    return '<p:sp>' . $nv . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/>'
         . '<a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="' . $prstGeom . '"><a:avLst/></a:prstGeom>'
         . $fill . $stroke . '</p:spPr>' . $tx . '</p:sp>';
}

function tbXml(array $el, int $id): string {
    $x = emu((int)$el['x']); $y = emu((int)$el['y']); $cx = emu((int)$el['w']); $cy = emu((int)$el['h']);
    $nv = sprintf('<p:nvSpPr><p:cNvPr id="%d" name="t%d"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>', $id, $id);
    return '<p:sp>' . $nv . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/>'
         . '<a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
         . txBodyXml($el['paras'], $el['w'], $el['h'], $el['valign']) . '</p:sp>';
}

const GRP_XFRM = '<a:xfrm><a:off x="0" y="0"/><a:ext cx="' . (1280 * 9525) . '" cy="' . (720 * 9525) . '"/><a:chOff x="0" y="0"/><a:chExt cx="' . (1280 * 9525) . '" cy="' . (720 * 9525) . '"/></a:xfrm>';

/** Assemble one slide XML from elements. */
function slideXml(array $els, string $bg, string $layoutName = 'Blank', int $layoutNum = 2): string {
    $id = 2;
    $shapes = '';
    foreach ($els as $el) {
        $shapes .= $el['k'] === 'shape' ? spXml($el, $id++) : tbXml($el, $id++);
    }
    $bgXml = '<p:bg><p:bgPr><a:solidFill><a:srgbClr val="' . $bg . '"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:cSld>' . $bgXml . '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr>' . GRP_XFRM . '</p:grpSpPr>'
        . $shapes . '</p:spTree></p:cSld>'
        . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
}

/** Assemble one notes slide XML. */
function notesXml(string $notes, int $slideNum): string {
    $t = esc($notes);
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:notes xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:spTree>'
        . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr>'.GRP_XFRM.'</p:grpSpPr>'
        . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="PlaceHolder Main Item"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>'
        . '<p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr><p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/>'
        . '<a:p><a:r><a:rPr lang="en-US" dirty="0"/><a:t>' . $t . '</a:t></a:r><a:endParaRPr lang="en-US"/></a:p>'
        . '</p:txBody></p:sp></p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:notes>';
}

/** Standard content-page chrome: kicker, title, accent rule, page number. Returns elements + consumed Y. */
function chrome(string $kicker, string $title, int $pageNo, int $total): array {
    $els = [];
    $els[] = tb(60, 34, 900, 26, [para([run(strtoupper($kicker), 12, C_AMBER, true)], 'left', 100)]);
    $els[] = tb(60, 58, 1120, 46, [para([run($title, 28, C_TEXT, true)], 'left', 108)]);
    $els[] = sh('line', 60, 116, 1160, 1, null, ['color' => C_BORDER, 'wPt' => 1]);
    $els[] = tb(1140, 678, 100, 26, [para([run($pageNo . ' / ' . $total, 11, C_LIGHT, false)], 'right', 100)]);
    return $els;
}

/** Card with tinted background and optional heading + body lines. */
function card(float $x, float $y, float $w, float $h, string $fill, array $paras, $stroke = null, string $radius = null): array {
    return sh('roundRect', $x, $y, $w, $h, $fill, $stroke, $paras, $radius);
}

/**
 * Write the whole deck into a .pptx package.
 * $slides: list of ['els' => [...], 'bg' => '#hex-no-#' or hex, 'notes' => 'string']
 */
function write_pptx(string $file, array $slides): void {
    $n = count($slides);
    $zip = new ZipArchive();
    if (is_file($file)) { unlink($file); }
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "cannot open $file\n"); exit(1);
    }

    // [Content_Types].xml
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
        . '<Override PartName="/ppt/presProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presProps+xml"/>'
        . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
        . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
        . '<Override PartName="/ppt/notesMasters/notesMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesMaster+xml"/>';
    for ($i = 1; $i <= $n; $i++) {
        $ct .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
    }
    for ($i = 1; $i <= $n; $i++) {
        $ct .= '<Override PartName="/ppt/notesSlides/notesSlide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml"/>';
    }
    $ct .= '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
        . '<Override PartName="/ppt/slideLayouts/slideLayout2.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
        . '</Types>';
    $zip->addFromString('[Content_Types].xml', $ct);

    // root rels
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
        . '</Relationships>');

    // presentation.xml
    $sldIds = ''; $rels = '';
    for ($i = 1; $i <= $n; $i++) {
        $sid = 255 + $i;
        $sldIds .= '<p:sldId id="' . $sid . '" r:id="rId' . $i . '"/>';
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $i . '.xml"/>';
    }
    $masterRid = 'rId' . ($n + 1);
    $propsRid  = 'rId' . ($n + 2);
    $themeRid  = 'rId' . ($n + 3);
    $rels .= '<Relationship Id="' . $masterRid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>'
          . '<Relationship Id="' . $propsRid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/presProps" Target="presProps.xml"/>'
          . '<Relationship Id="' . $themeRid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>';
    $zip->addFromString('ppt/presentation.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="' . $masterRid . '"/></p:sldMasterIdLst>'
        . '<p:sldIdLst>' . $sldIds . '</p:sldIdLst>'
        . '<p:sldSz cx="' . emu(SW) . '" cy="' . emu(SH) . '"/><p:notesSz cx="' . emu(SH) . '" cy="' . emu(SW) . '"/>'
        . '<p:defaultTextStyle><a:defPPr><a:defRPr lang="en-US"/></a:defPPr></p:defaultTextStyle>'
        . '</p:presentation>');
    $zip->addFromString('ppt/_rels/presentation.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');

    $zip->addFromString('ppt/presProps.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:presentationPr xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');

    // slide master (minimal, no placeholders so slides never demand them)
    $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:bg>'
        . '<p:bgPr><a:solidFill><a:srgbClr val="' . C_BG . '"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>'
        . '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr>'.GRP_XFRM.'</p:grpSpPr>'
        . '</p:spTree></p:cSld><p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
        . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/><p:sldLayoutId id="2147483650" r:id="rId2"/></p:sldLayoutIdLst>'
        . '</p:sldMaster>');
    $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
        . '</Relationships>');

    // blank layouts (no placeholder shapes)
    foreach ([1, 2] as $lnum) {
        $nm = $lnum === 1 ? 'Office Theme' : 'Blank';
        $zip->addFromString("ppt/slideLayouts/slideLayout{$lnum}.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="' . ($lnum === 1 ? 'officeTheme' : 'blank') . '">'
            . '<p:cSld name="' . $nm . '"><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr>'.GRP_XFRM.'</p:grpSpPr>'
            . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>');
        $zip->addFromString("ppt/slideLayouts/_rels/slideLayout{$lnum}.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
            . '</Relationships>');
    }

    // notes master
    $zip->addFromString('ppt/notesMasters/notesMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:notesMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:spTree>'
        . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr>'.GRP_XFRM.'</p:grpSpPr>'
        . '</p:spTree></p:cSld><p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/></p:notesMaster>');
    $zip->addFromString('ppt/notesMasters/_rels/notesMaster1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
        . '</Relationships>');

    // theme
    $zip->addFromString('ppt/theme/theme1.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="TrustMart SV"><a:themeElements><a:clrScheme name="TrustMart">
<a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>
<a:dk2><a:srgbClr val="132A2E"/></a:dk2><a:lt2><a:srgbClr val="E9F1F3"/></a:lt2>
<a:accent1><a:srgbClr val="0E7C86"/></a:accent1><a:accent2><a:srgbClr val="D97E1F"/></a:accent2>
<a:accent3><a:srgbClr val="2E9E5B"/></a:accent3><a:accent4><a:srgbClr val="5B6B72"/></a:accent4>
<a:accent5><a:srgbClr val="AEBEC4"/></a:accent5><a:accent6><a:srgbClr val="E4F1F2"/></a:accent6>
<a:hlink><a:srgbClr val="0E7C86"/></a:hlink><a:folHlink><a:srgbClr val="D97E1F"/></a:folHlink></a:clrScheme>
<a:fontScheme name="TrustMart"><a:majorFont><a:latin typeface="Calibri Light"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>
<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>
<a:fmtScheme name="Office"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>
<a:lnStyleLst><a:ln w="6350" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln><a:ln w="12700" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln><a:ln w="19050" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln></a:lnStyleLst>
<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>
<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst></a:fmtScheme></a:themeElements></a:theme>
XML);

    // slides + notes
    for ($i = 1; $i <= $n; $i++) {
        $s = $slides[$i - 1];
        $layoutRid = ($s['layout'] ?? 'Blank') === 'Blank' ? 'rId1' : 'rId1';
        $zip->addFromString("ppt/slides/slide{$i}.xml", slideXml($s['els'], $s['bg']));
        $zip->addFromString("ppt/slides/_rels/slide{$i}.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout2.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide" Target="../notesSlides/notesSlide' . $i . '.xml"/>'
            . '</Relationships>');
        $zip->addFromString("ppt/notesSlides/notesSlide{$i}.xml", notesXml($s['notes'], $i));
        $zip->addFromString("ppt/notesSlides/_rels/notesSlide{$i}.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="../slides/slide' . $i . '.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesMaster" Target="../notesMasters/notesMaster1.xml"/>'
            . '</Relationships>');
    }

    $zip->close();
    fwrite(STDOUT, "WROTE {$file} slides={$n}\n");
}
