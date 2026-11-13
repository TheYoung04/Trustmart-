<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/pptx_helpers.php';

/* Convenience wrappers ---------------------------------------------------- */
function hl(string $t, float $pt = 13, string $c = C_TEXT): array { return para([run($t, $pt, $c, true)], 'left', 112, 6); }
function bl(string $t): array { return para([run($t, 11, C_MED)], 'left', 116, 4, true); }
function cardParas(string $head, array $lines, string $headColor = C_TEXT): array {
    $p = [hl($head, 13, $headColor)];
    foreach ($lines as $l) { $p[] = bl($l); }
    return $p;
}

$TOTAL = 13;
$S = [];

/* 1 — Cover (dark minimal) ------------------------------------------------- */
$els = [];
$els[] = sh('rect', 0, 0, 1280, 720, C_DARK);
$els[] = sh('line', 60, 96, 690, 1, null, ['color' => '2C4A50', 'wPt' => 1]);
$els[] = tb(60, 104, 660, 24, [para([run('KONSULTASI SUPERVISOR · PROJEK AKHIR', 12, C_AMBER, true)], 'left', 100)]);
$els[] = tb(60, 150, 700, 100, [para([run('TrustMart', 54, 'FFFFFF', true)], 'left', 100)]);
$els[] = tb(60, 260, 700, 84, [
    para([run('Marketplace dengan pengescoran kepercayaan hibrid', 21, C_ONDARK)], 'left', 120, 6),
    para([run('status semasa, bukti UAT & perkara yang perlu diputuskan', 15, C_LIGHT)], 'left', 115),
]);
$els[] = sh('roundRect', 790, 168, 430, 244, '1B383D', ['color' => '2C4A50', 'wPt' => 1], [
    para([run('64 / 64', 48, '5FC8D1', true)], 'center', 100, 4),
    para([run('semakan automasi UAT LULUS', 13, C_ONDARK)], 'center', 115, 3),
    para([run('PEMBELI 19 · PENJUAL 23 · PENTADBIR 22', 11, C_LIGHT)], 'center', 115, 0),
], 'roundRect');
$els[] = sh('line', 60, 590, 1160, 1, null, ['color' => '2C4A50', 'wPt' => 1]);
$els[] = tb(60, 604, 900, 60, [
    para([run('Sesi konsultasi: 29 Oktober 2026', 12, C_ONDARK)], 'left', 120, 3),
    para([run('Build: tempatan XAMPP · localhost:8080 · MariaDB "trustmart" · docs/uat (larian 13 Okt 2026)', 11, C_LIGHT)], 'left', 115),
]);
$S[] = ['els' => $els, 'bg' => C_DARK, 'notes' =>
    '30 saat. objective hari ini: tunjuk apa sudah dibina, bukti kualiti (64/64 UAT lulus), demo alur penuh, dan minta keputusan SV atas empat perkara di slide akhir. Build yang dipaparkan ialah build tempatan yang sama dengan yang diuji.'];

/* 2 — What & Why ----------------------------------------------------------- */
$els = chrome('APA & MENGAPA', 'Marketplace yang direka untuk meminimumkan scam', 2, $TOTAL);
$els[] = tb(60, 136, 560, 330, [
    para([run('Masalah: scam dalam jualan dalam talian — barangan tidak sampai, bayaran tidak keluar, penilaian palsu.', 13, C_TEXT)], 'left', 128, 14),
    para([run('Jawapan TrustMart: setiap transaksi dan interaksi menyumbang kepada skor kepercayaan penjual — bukan sekadar rating bintang.', 13, C_TEXT)], 'left', 128, 14),
    para([run('Tiga role: Pembeli, Penjual, Pentadbir — pemisahan akses penuh, disahkan oleh suite UAT.', 13, C_TEXT)], 'left', 128, 10),
    para([run('Lingkung penuh: daftar → aktif → sah penjual → listing → order → bayaran → selesai/refund/dispute.', 12, C_MED)], 'left', 124),
]);
$cx = 660; $cw = 560;
$els[] = card($cx, 136, $cw, 150, C_CARD, [
    hl('Skor kepercayaan hibrid', 14, C_ACCENT),
    bl('Teras Bayesian deterministik dalam MySQL (trigger + stored procedure)'),
    bl('Lapisan AI: pengesanan scam dalam chat & kredibiliti review'),
    bl('Paparan satu skor efektif; badge AI hanya bila kedua-dua berbeza'),
]);
$els[] = card($cx, 302, $cw, 150, C_CARD, [
    hl('Bayaran & status terkunci', 14, C_ACCENT),
    bl('Gateway ToyyibPay + simulator; satu penulis terminal-state yang idempotent'),
    bl('Order tidak boleh "naik taraf" selepas selesai; timeout luput automatik'),
    bl('Stok dipulih tepat satu kali pada setiap lintasan batal/luput'),
]);
$els[] = card($cx, 468, $cw, 150, C_CARD, [
    hl('Pentadbiran penuh kawalan', 14, C_ACCENT),
    bl('Gerbang refund: pulangan wajib disahkan sebelum refund siap'),
    bl('Dispute diselesaikan admin → kesan terus ke skor kepercayaan'),
    bl('CSRF, 403/401/302 mengikut role — semua lulus ujian negatif'),
]);
$els[] = tb(60, 500, 560, 120, [
    para([run('Kenapa penting untuk viva: ', 12, C_AMBER, true), run('sistem ini gabungan reka bentuk (state machine, kepercayaan) dan disiplin kejuruteraan (UAT automasi, regresi, migrasi skema v2–v13).', 12, C_MED)], 'left', 126),
]);
$S[] = ['els' => $els, 'bg' => C_BG, 'notes' =>
    '90 saat. Frame masalah (scam P2P) → jawapan (trust score yang dipacu transaksi sebenar). Tiga kad kanan ialah tiga tulang belakang teknikal yang akan diperincikan slide berikutnya.'];

/* 3 — Hybrid trust scoring -------------------------------------------------- */
$els = chrome('TERAS TEKNIKAL', 'Skor kepercayaan: teras (skop proposal) + AI (tambahan)', 3, $TOTAL);
$els[] = card(60, 140, 565, 250, C_ACC_T, [
    para([run('1 · Teras deterministik (MySQL) — SKOP', 15, C_ACCENT, true)], 'left', 110, 8),
    bl('Bayesian/rule-based dikira dalam trigger & stored procedure atas transaksi, dispute dan review'),
    bl('trust_profiles mengikut role — "Baharu" (kelabu) untuk penjual tanpa bukti, bukan 0/100 yang mengelirukan'),
    bl('Deterministik & boleh audit: skor yang sama dipapar di kad katalog, halaman produk dan header chat melalui trust_display_from_row()'),
], ['color' => 'BBD8DB', 'wPt' => 1]);
$els[] = card(655, 140, 565, 250, C_AMB_T, [
    para([run('2 · Lapisan AI (overlay) — LUAR SKOP', 15, C_AMBER, true)], 'left', 110, 8),
    bl('Menganalisis isyarat scam dalam chat, kredibiliti review dan corak tingkah laku → fraud_risk'),
    bl('Lapisan boleh audit: skor AI ialah semakan tambahan, bukan pengganti teras'),
    bl('Gerbang checkout: fraud_risk ≥ AI_TRUST_HOLD_RISK dan ai_hold=1 → order ditahan melainkan admin lepaskan'),
], ['color' => 'EED9BC', 'wPt' => 1]);
$els[] = card(60, 414, 1160, 130, C_CARD, [
    para([run('Kedudukan skop: proposal hanya mengikat teras deterministik', 14, C_TEXT, true)], 'left', 108, 6),
    para([run('§4.0/§5.0 proposal: "algoritma maklum balas yang telus" + sejarah transaksi = teras MySQL (diuji UAT 64/64). AI TM-AI-1.0 ialah lapisan optional (AI_TRUST_ENABLED) untuk isyarat scam §2.0 — ditambah sebagai nilai tambah, bukan pengganti teras.', 12, C_MED)], 'left', 122),
]);
$els[] = tb(60, 562, 1160, 66, [
    para([run('Untuk viva: ', 12, C_ACCENT, true), run('bentangkan teras sebagai deliverable yang diikat proposal; AI sebagai bonus yang berfungsi (ujian pengesan 15/15 + gate 6/6 lulus) supaya skop dan bukti tetap selari.', 12, C_MED)], 'left', 124),
]);
$S[] = ['els' => $els, 'bg' => C_BG, 'notes' =>
    '2 minit. Teras = MySQL trigger/stored procedure (boleh audit, deterministik) — INI yang diikat proposal (§4.0/§5.0 algoritma telus). AI = overlay optional yang saya tambah untuk isyarat scam §2.0. Bincang jujur: AI di luar skop proposal, jadi bentang sebagai bonus, bukan teras. Sudah disahkan berfungsi: pengesan 15/15 + gate 6/6 lulus.'];

/* 4 — Trust score mechanics -------------------------------------------------- */
$els = chrome('TERAS TEKNIKAL', 'Bagaimana skor kepercayaan dikira — tiga langkah', 4, $TOTAL);

// ── Langkah 1: the five weighted components (weights read from the procedure) ──
$els[] = card(60, 136, 565, 318, C_CARD, [
    para([run('1 · LIMA KOMPONEN', 14, C_TEXT, true), run('   jumlah maksimum 100', 11, C_MED)], 'left', 100, 6),
]);
// Descriptors are capped at ~33 characters: fit_audit showed that a 45-char line
// at 10pt needs ~297px but the label column is only 250px usable, so it wrapped
// into a second line and collided with the row beneath it.
$comps = [
    ['Ulasan pembeli disahkan', 'Bayesian — pembeli selesai sahaja', 35],
    ['Kebolehpercayaan pesanan', 'selesai vs dibatalkan penjual', 30],
    ['Keselamatan pertikaian', 'aduan diputus memihak pembeli', 20],
    ['Respons keputusan', 'sahkan/tolak dalam 12 jam', 10],
    ['Pengesahan identiti PTU', 'dokumen disemak pentadbir', 5],
];
$by = 186;
foreach ($comps as $c) {
    $els[] = tb(70, $by - 3, 46, 28, [para([run((string)$c[2], 16, C_ACCENT, true)], 'right', 100)]);
    $els[] = tb(124, $by - 4, 260, 22, [para([run($c[0], 12, C_TEXT, true)], 'left', 100)]);
    $els[] = tb(124, $by + 17, 260, 16, [para([run($c[1], 10, C_MED)], 'left', 100)]);
    $els[] = sh('roundRect', 392, $by + 4, 188, 13, C_BORDER, null, [], 'roundRect');
    $els[] = sh('roundRect', 392, $by + 4, round(188 * $c[2] / 35), 13, C_ACCENT, null, [], 'roundRect');
    $by += 52;
}

// ── Langkah 2: confidence — the part that makes the score honest ─────────────
$els[] = card(655, 136, 565, 318, C_ACC_T, [
    para([run('2 · KEYAKINAN', 14, C_ACCENT, true), run('   berapa ramai yang menyokong', 11, C_MED)], 'left', 100, 6),
    para([run('Skor mentah didarab dengan tahap keyakinan, supaya penjual baharu tidak boleh mengatasi penjual berpengalaman.', 11, C_TEXT)], 'left', 126, 8),
    para([run('Bukti (E) dikira mengikut pembeli unik, maksimum 3 peristiwa seorang — seorang pembeli tidak boleh menggelembungkan skor.', 11, C_TEXT)], 'left', 126, 10),
], ['color' => 'BBD8DB', 'wPt' => 1]);
$els[] = card(675, 258, 525, 34, 'FFFFFF', [
    para([run('keyakinan  =  100 × ln(1 + E) ÷ ln(11)', 13, C_ACCENT, true)], 'center', 100)], null, 'roundRect');
$els[] = tb(675, 298, 525, 22, [para([run('LEBIH BANYAK BUKTI  →  LEBIH TINGGI KEYAKINAN', 10, C_MED, true)], 'left', 100)]);
$chips = [['E = 1', '28.9 %'], ['E = 2', '45.8 %'], ['E = 3', '57.8 %'], ['E = 5', '74.7 %'], ['E = 10', '100 %']];
foreach ($chips as $i => $ch) {
    $x = 675 + $i * 110;
    $els[] = card($x, 322, 95, 50, 'FFFFFF', [
        para([run($ch[0], 10, C_MED)], 'center', 100, 2),
        para([run($ch[1], 13, C_ACCENT, true)], 'center', 100),
    ], ['color' => 'BBD8DB', 'wPt' => 1], 'roundRect');
}
$els[] = tb(675, 384, 525, 52, [
    para([run('E = 0  →  ', 11, C_AMBER, true),
          run('tiada nombor langsung. Lencana "Baharu" dipapar, bukan 0/100 yang mengelirukan.', 11, C_TEXT)], 'left', 124),
]);

// ── Langkah 3: three real sellers from this database, no invented numbers ────
$els[] = tb(60, 456, 1160, 30, [
    para([run('3 · HASILNYA', 14, C_TEXT, true),
          run('   tiga penjual sebenar dalam pangkalan data ini — boleh disemak di trust_profiles', 11, C_MED)], 'left', 100),
]);
// Card height is sized to the copy: 4 paragraphs, one of which is a 24pt number.
// Widths are (1160 - 2*20 gap) / 3 so the third card ends at 1219, inside the
// 60px margin — the old 370px/400px step pushed it to 1230, off the canvas.
$examples = [
    ['Nurul Ain', 'Mentah 79.25 · E = 0 · Keyakinan 0 %', 'Baharu', C_LIGHT,
     'Belum ada satu pun pesanan selesai.', C_CARD],
    ['Siti Hajar', 'Mentah 76.04 · E = 2 · Keyakinan 45.8 %', '34.8', C_AMBER,
     'Rekod baik, bukti nipis — dipotong separuh.', C_AMB_T],
    ['Mirza', 'Mentah 69.25 · E = 3 · Keyakinan 57.8 %', '40.0', C_GREEN,
     'Mentah paling rendah, paparan paling tinggi.', C_GRN_T],
];
foreach ($examples as $i => $e) {
    $x = 60 + $i * 393;
    $els[] = card($x, 488, 373, 118, $e[5], [
        para([run($e[0], 12, C_TEXT, true)], 'left', 100, 2),
        para([run($e[1], 9.5, C_MED)], 'left', 105, 5),
        para([run($e[2], 24, $e[3], true)], 'left', 100, 3),
        para([run($e[4], 9.5, C_MED)], 'left', 120),
    ], null, 'roundRect');
}

$els[] = tb(60, 612, 1160, 26, [
    para([run('Kesimpulan: ', 11, C_ACCENT, true),
          run('keyakinan mengalahkan kecemerlangan tanpa bukti — Mirza skor mentahnya paling rendah, tetapi skor dipaparnya paling tinggi.', 11, C_TEXT)], 'left', 100),
]);

// ── Provenance strip — one line, so it cannot collide with the page number ───
$els[] = sh('line', 60, 644, 1160, 1, null, ['color' => C_BORDER, 'wPt' => 1]);
$els[] = tb(60, 650, 1160, 24, [
    para([run('Boleh diaudit: ', 10, C_ACCENT, true),
          run('formula TM-TRUST-2.0 · stored procedure MySQL · setiap komponen ada baris audit sendiri dalam trust_score_components', 10, C_MED)], 'left', 100),
]);
$S[] = ['els' => $els, 'bg' => C_BG, 'notes' =>
    '2 minit. Ini slide penjelasan skor — guna selepas slide 3. CERITA: (1) lima komponen, berat berbeza — ulasan 35, pemenuhan 30, pertikaian 20, respons 10, identiti 5; tiada satu pun boleh menang seorang diri. (2) Keyakinan ialah bahagian yang buat skor ini jujur: bukti dikira mengikut pembeli unik, cap 3 seorang. (3) Puncanya: Mirza skor MENTAH paling rendah (69.25) tetapi PAPARAN paling tinggi (40.0) — sebab dia punya 3 unit bukti, Siti Hajar 2, Nurul Ain 0. Jangan cakap "sistem bagi 40 markah"; cakap "sistem belum cukup bukti untuk percaya skor ini". Jika SV tanya kenapa cap 3: supaya seorang pembeli yang buat 20 pesanan tidak jadi 20 suara. Nombor semua dari baris trust_profiles yang hidup — boleh buka phpMyAdmin dan tunjuk.'];

/* 5 — Live demo script (8 steps) -------------------------------------------- */
$els = chrome('SESI DEMO', 'Laluan demo langsung — 8 langkah, satu pusingan penuh', 5, $TOTAL);
$steps = [
    ['1', 'Login pembeli', 'katalog dengan kad skor kepercayaan'],
    ['2', 'Butiran produk', 'AI Trust breakdown + skor konsisten'],
    ['3', 'Checkout', 'satu pemilih: Bayar Terus / Dalam Talian'],
    ['4', 'Bayar + gateway_sim', 'satu penulis terminal-state idempotent'],
    ['5', 'Resit + My Orders', 'status Menunggu → rekod bayaran'],
    ['6', 'Seller sahkan', 'Selesai hanya oleh pembeli'],
    ['7', 'Review → skor berubah', 'header chat & kad ikut trust_profiles'],
    ['8', 'Admin console', 'refund (gerbang), dispute, panel risiko AI'],
];
$bw = 272; $gap = 24; $x0 = 60;
foreach ($steps as $i => $st) {
    $col = $i % 4; $row = intdiv($i, 4);
    $x = $x0 + $col * ($bw + $gap); $y = 150 + $row * 218;
    $els[] = card($x, $y, $bw, 190, C_CARD, [
        para([run($st[0], 30, C_ACCENT, true)], 'left', 100, 6),
        para([run($st[1], 13, C_TEXT, true)], 'left', 110, 4),
        para([run($st[2], 11, C_MED)], 'left', 118),
    ]);
    if ($col < 3) {
        $els[] = sh('arrow', $x + $bw + 2, $y + 84, 20, 22, C_LIGHT, null, [], 'arrowRight');
    }
}
$els[] = tb(60, 596, 1160, 60, [
    para([run('Persediaan demo: ', 12, C_AMBER, true), run('sedia data dispute terbuka + profil fraud_risk > 0 (kini kosong — item data-blocked); tab login berasingan untuk pembeli/penjual/admin; screenshot simpanan jika demo gagal.', 12, C_MED)], 'left', 124),
]);
$S[] = ['els' => $els, 'bg' => C_BG, 'notes' =>
    '8-10 minit selepas slide ini, demo langsung mengikut tertib kad. Jangan present kod; tunjuk flows. Jika demo rosak, tukar ke screenshot (payment_success.png, uat_A8_ai_risk_panel.png dll di akar repo).'];

/* 6 — Order state machine ---------------------------------------------------- */
$els = chrome('MESIN KEADAAN', 'Kitar hayat order: tiada lintasan yang terbuka', 6, $TOTAL);
$els[] = card(90, 170, 240, 84, C_ACC_T, [para([run('Menunggu', 16, C_ACCENT, true)], 'center', 105, 2), para([run('belum dibayar', 11, C_MED)], 'center', 110)], ['color' => 'BBD8DB', 'wPt' => 1], 'roundRect');
$els[] = sh('chev', 348, 196, 52, 32, C_ACCENT, null, [], 'chevron');
$els[] = card(418, 170, 240, 84, C_ACC_T, [para([run('Disahkan', 16, C_ACCENT, true)], 'center', 105, 2), para([run('dibayar / penjual sahkan', 11, C_MED)], 'center', 110)], ['color' => 'BBD8DB', 'wPt' => 1], 'roundRect');
$els[] = sh('chev', 676, 196, 52, 32, C_ACCENT, null, [], 'chevron');
$els[] = card(746, 170, 240, 84, C_GRN_T, [para([run('Selesai', 16, C_GREEN, true)], 'center', 105, 2), para([run('hanya pembeli boleh tandakan', 11, C_MED)], 'center', 110)], ['color' => 'BFE0CC', 'wPt' => 1], 'roundRect');
$els[] = card(1020, 170, 200, 84, C_AMB_T, [para([run('Dibatalkan', 15, C_AMBER, true)], 'center', 105, 2), para([run('terminal batal', 11, C_MED)], 'center', 110)], ['color' => 'EED9BC', 'wPt' => 1], 'roundRect');
$els[] = sh('line', 986, 212, 34, 1, null, ['color' => C_LIGHT, 'wPt' => 1.5]);
$els[] = sh('line', 676, 300, 1, 1, null, ['color' => C_LIGHT, 'wPt' => 1]);
// Invariant cards
$els[] = card(90, 320, 545, 190, C_CARD, [
    para([run('Peraturan penguatkuasaan', 14, C_TEXT, true)], 'left', 108, 6),
    bl('Batal sendiri oleh pembeli hanya pada Menunggu; Disahkan → alur dispute'),
    bl('Penjual tidak boleh sahkan order dalam talian yang belum dibayar'),
    bl('Stok dipulih tepat satu kali pada SEMUA lintasan batal termasuk luput (expire_unpaid.php)'),
    bl('Bayaran lewat diselesaikan semasa callback — payment selesai tidak sesekali diturun taraf'),
], null);
$els[] = card(655, 320, 565, 190, C_CARD, [
    para([run('Lintasan tepi', 14, C_TEXT, true)], 'left', 108, 6),
    bl('Bayaran Luput: order belum bayar automatik luput selepas PAYMENT_TIMEOUT_MINUTES + stok dipulih'),
    bl('Dispute: pembeli lapor → admin selesaikan → kesan terus kepada skor kepercayaan kedua pihak'),
    bl('Refund (butuh barang): kunci pada gerbang can_proceed_refund — lihat slide 7'),
], null);
$els[] = tb(90, 540, 1130, 80, [
    para([run('Satu penulis terminal-state: ', 12, C_ACCENT, true), run('gateway_sim dan ToyyibPay dua-dua lalu apply_payment_gateway_result() (config/payment.php) — FOR UPDATE, idempotent, tiada direct write, tiada loopback.', 12, C_MED)], 'left', 124),
]);
$S[] = ['els' => $els, 'bg' => C_BG, 'notes' =>
    '2 minit. Ini jawapan kepada soalan "macam mana sistem jamin tak hilang duit/barang". Tekankan invariant: Selesai hanya oleh pembeli, stok pulih sekali sahaja, dan satu penulis terminal-state untuk dua-dua gateway.'];

/* 7 — UAT evidence ----------------------------------------------------------- */
$els = chrome('BUKTI KUALITI', 'UAT automasi: 64/64 lulus, 0 gagal', 7, $TOTAL);
// Donut visual: full ring = 100% pass
$els[] = sh('donut', 90, 150, 190, 190, C_ACCENT, null, [], 'donut');
$els[] = tb(90, 214, 190, 60, [para([run('100%', 24, 'FFFFFF', true)], 'center', 100)]);
$els[] = tb(60, 356, 250, 60, [
    para([run('sumber: docs/uat/UAT_SUMMARY.md', 10, C_LIGHT)], 'left', 118, 3),
    para([run('larian 13 Okt 2026, run_all.php', 10, C_LIGHT)], 'left', 118),
]);
$els[] = tb(370, 148, 500, 26, [para([run('Suite setiap role (tests/uat/run_all.php)', 12, C_TEXT, true)], 'left', 100)]);
// bar rows (full-width bars = 100% pass)
$rows = [['PEMBELI', '19 / 19'], ['PENJUAL', '23 / 23'], ['PENTADBIR', '22 / 22']];
foreach ($rows as $i => $r) {
    $y = 184 + $i * 46;
    $els[] = tb(370, $y, 110, 26, [para([run($r[0], 12, C_TEXT, true)], 'left', 100)]);
    $els[] = sh('roundRect', 490, $y + 3, 300, 18, C_BORDER, null, [], 'roundRect');
    $els[] = sh('roundRect', 490, $y + 3, 300, 18, C_ACCENT, null, [], 'roundRect');
    $els[] = tb(800, $y, 90, 26, [para([run($r[1], 12, C_GREEN, true)], 'left', 100)]);
}
// Pre-existing spill: this card was 150px tall but its three bullets need ~174px,
// so the last line ran past the tinted edge. Grown to 182 and nudged up 8px;
// bottom lands at 504, clear of the "Defek ditemui" line at 512.
$els[] = card(370, 322, 520, 182, C_CARD, [
    para([run('Apa yang dicakup 64 assertion', 13, C_TEXT, true)], 'left', 108, 5),
    bl('Akses silang-role: 403 (salah role) · 401+redirect (API tanpa sesi) · 302 (halaman) — 9 gabungan diverifikasi'),
    bl('CSRF ditolak pada ketiga-tiga role; pemilikan objek (penjual tak boleh ubah listing orang lain)'),
    bl('Kitar hidup produk add→edit→soft-delete + kontrak refund-tab (pending_return)'),
], null);
$els[] = card(920, 148, 300, 356, C_CARD, [
    para([run('Harness tambahan (scratch/)', 12.5, C_TEXT, true)], 'left', 106, 6),
    para([run('test_refund_tab', 11, C_MED)], 'left', 112, 1),
    para([run('18 / 18 LULUS', 13, C_GREEN, true)], 'left', 110, 8),
    para([run('test_admin_returns_fix', 11, C_MED)], 'left', 112, 1),
    para([run('16 / 16 LULUS', 13, C_GREEN, true)], 'left', 110, 8),
    para([run('test_dispute_trust_effect', 11, C_MED)], 'left', 112, 1),
    para([run('7 / 7 LULUS', 13, C_GREEN, true)], 'left', 110, 8),
    para([run('test_e2e_refund_gate', 11, C_MED)], 'left', 112, 1),
    para([run('13 / 13 LULUS', 13, C_GREEN, true)], 'left', 110, 4),
]);
$els[] = tb(60, 512, 1160, 90, [
    para([run('Defek ditemui & diselesaikan: ', 12, C_ACCENT, true), run('DEFECT-001 (halaman Pulangan admin 500 + yatim) dan DEFECT-002 (gerbang refund tak dikuatkuasa) — dua-dua Resolved dengan pengawal regresi. Data ujian self-cleaning: rekod crafted dipadam selepas larian, tiada polusi demo.', 12, C_MED)], 'left', 126),
]);
$S[] = ['els' => $els, 'bg' => C_BG, 'notes' =>
    '2 minit. Slide bukti utama. 64/64 suite role + 4 harness sasaran. PENTING: jangan cakap "semua ujian lulus" — sebut gaps manual di slide 8. Semua nombor dari docs/uat/UAT_SUMMARY.md larian 13 Okt.'];

/* 8 — Refund gate deep-dive --------------------------------------------------- */
$els = chrome('BUKTI KEDALAMAN', 'DEFECT-002: gerbang "tunggu barang dulu" kini dikuatkuasa', 8, $TOTAL);
$els[] = card(60, 140, 1160, 128, C_CARD, [
    para([run('Masalah (Medium): ', 13, C_AMBER, true), run('stored procedure can_proceed_refund wujud dalam skema tetapi tidak pernah dipanggil — refund boleh ditanda siap sebelum barangan pulang disahkan. Risiko: penjual rugi dua kali (duit + barang), pencetus dispute.', 12, C_MED)], 'left', 124),
]);
$els[] = card(60, 292, 565, 210, C_ACC_T, [
    para([run('Penyelesaian (kod)', 14, C_ACCENT, true)], 'left', 108, 6),
    bl('admin.php → refund_can_proceed(PDO,int) memanggil stored procedure can_proceed_refund'),
    bl('retry_refund + mark_refund_completed: 409 + p_reason, TIADA perubahan keadaan bila pulangan belum disahkan'),
    bl('Lepasan sah hanya via admin_returns.php: verify → refund_status jadi "requested", atau waive dengan sebab bertulis'),
], ['color' => 'BBD8DB', 'wPt' => 1]);
$els[] = card(655, 292, 565, 210, C_GRN_T, [
    para([run('Pengawal regresi', 14, C_GREEN, true)], 'left', 108, 6),
    bl('scratch/test_e2e_refund_gate.php — 13/13 LULUS, self-cleaning'),
    bl('Craft txn + payment selesai + dispute settlement_return_required + return_shipment, semak 409, kemudian padam + rekira trust'),
    bl('Fail di scratch/ (bukan tests/) — path sebenar dipetik untuk ketepatan'),
], ['color' => 'BFE0CC', 'wPt' => 1]);
$els[] = tb(60, 528, 1160, 66, [
    para([run('Nilai kejuruteraan: ', 12, C_TEXT, true), run('bukan sekadar "pass test" — rule ditakrif dalam DB, dipaku dengan ujian yang menjana data sebenar dan bersih sendiri; tiada dead-end kerana laluan verify/waive kekal ada.', 12, C_MED)], 'left', 124),
]);
$S[] = ['els' => $els, 'bg' => C_BG, 'notes' =>
    '1.5 minit. Ini menunjukkan kedalaman: defect → punca → gate di dua endpoint → regression guard. Jika SV tanya kenapa 409 dan bukan sembunyi button: jawapan = API konsisten untuk semua klien, alasan dipulangkan (p_reason).'];

/* 9 — Gaps & prep ------------------------------------------------------------- */
$els = chrome('RISIKO & PERSIAPAN', 'Yang masih terbuka (jujur, bukan suit automasi)', 9, $TOTAL);
$gaps = [
    ['Bayaran provider sebenar', 'ToyyibPay round-trip diliput script scratch/test_e2e_*, perlu re-run pada build ini sebelum sign-off'],
    ['Emel & notifikasi', 'terima kasih, pengaktifan, notifikasi refund — perlu semakan manusia (tone & isi)'],
    ['Gerbang penjual belum sah', 'tak boleh uji automasi — semua penjual demo sudah Disahkan; perlu account "Menunggu" baharu'],
    ['Kepercayaan review/pemenuhan', 'kesan dispute sahaja yang diverifikasi; review & corak pemenuhan belum automasi'],
    ['Muat / mudah alih / aksesibiliti', 'di luar skop pusingan ini — TO CONFIRM skop viva'],
];
$y = 142;
foreach ($gaps as $i => $g) {
    $els[] = card(60, $y, 700, 88, C_CARD, [
        para([run($g[0], 12.5, C_TEXT, true)], 'left', 105, 3),
        para([run($g[1], 11, C_MED)], 'left', 116),
    ]);
    $y += 100;
}
$els[] = card(790, 142, 430, 468, C_AMB_T, [
    para([run('Persediaan sebelum demo esok', 14, C_AMBER, true)], 'left', 108, 8),
    bl('Seed 1 dispute berstatus terbuka (A4 kini kosong — data-blocked)'),
    bl('Seed profil dengan fraud_risk > 0 supaya panel risiko AI tidak kosong (A8)'),
    bl('Semak 1 penjual "Menunggu" untuk tunjuk gerbang pengesahan PTU'),
    bl('Reset demo accounts + pastikan katalog 10 produk uploads ada'),
    bl('Buka tab login buyer/seller/admin siap-siap'),
], ['color' => 'EED9BC', 'wPt' => 1]);
$S[] = ['els' => $els, 'bg' => C_BG, 'notes' =>
    '1 minit. SV akan hormati kejujuran coverage gaps — ini dari UAT_SUMMARY.md s4. Kad kanan checklist konkret sebelum masuk sesi: dua item admin (dispute, AI risk) kini kosong data, jadi seed dulu atau panel nampak rosak semasa demo.'];

/* 10 — Questions for SV -------------------------------------------------------- */
$els = chrome('PERBINCANGAN', 'Empat perkara yang memerlukan keputusan/pendapat SV', 10, $TOTAL);
$qs = [
    ['Q1 · Gerbang DEFECT-002', 'Pendekatan "kuatkan, bukan bersara" (409 + simpan prosedur) sudah betul dari segi perniagaan, atau flow patut berbeza — contohnya pembeli sah pulangan dulu?'],
    ['Q2 · Adakah AI dalam skop?', 'Proposal menetapkan algoritma skor kepercayaan yang telus, bukan AI. Terima lapisan AI sebagai penambahbaikan luar skop (bukti: pengesan 15/15 + gate 6/6), atau saya kurangkan penekanan & pusat viva pada teras deterministik?'],
    ['Q3 · Jurang liputan', 'mana satu wajib untuk viva: round-trip ToyyibPay sebenar, emel, gerbang penjual belum sah, atau UI mudah alih/aksesibiliti?'],
    ['Q4 · Skop code-freeze', 'Setuju beku setelah 4 keputusan ini? Calon freeze: dokumen UAT lengkap, semua regresi hijau; tinggal polish sahaja.'],
];
foreach ($qs as $i => $q) {
    $col = $i % 2; $row = intdiv($i, 2);
    $x = 60 + $col * 595; $yy = 148 + $row * 250;
    $els[] = card($x, $yy, 565, 226, $i % 2 === 0 ? C_ACC_T : C_AMB_T, [
        para([run($q[0], 15, $col === 0 ? C_ACCENT : C_AMBER, true)], 'left', 105, 10),
        para([run($q[1], 12.5, C_TEXT)], 'left', 128),
    ], ['color' => $i % 2 === 0 ? 'BBD8DB' : 'EED9BC', 'wPt' => 1]);
}
$S[] = ['els' => $els, 'bg' => C_BG, 'notes' =>
    '5 minit — slide paling penting. Q1: keputusan reka bentuk perniagaan. Q2: skop AI — proposal tak sebut AI, terima sebagai penambahbaikan atau kurangkan penekanan. Q3: skop pengujian akhir. Q4: garis masa. Minta keputusan SV dicatat; jangan tamat sesi tanpa Q2, Q3 dan Q4 dijawab.'];

/* 11 — Roadmap ---------------------------------------------------------------- */
$els = chrome('GARIS MASA', 'Rancangan sehingga viva', 11, $TOTAL);
$phases = [
    ['Hari ini', 'Keputusan Q1–Q4 + rekod arahan SV dalam DEFECTS.md'],
    ['Minggu ini', 'Seed data demo · re-run scratch/test_e2e_* · checklist manual §5 UAT_SUMMARY'],
    ['Seterusnya', 'Semakan UI/manual (emel, empty states, mudah alih) · tutup jurang yang dipersetujui'],
    ['Freeze', 'Kod beku · suite 64-check masuk setiap release (minit sahaja, self-clean) · laporan akhir'],
];
$x = 60; $wSeg = 278;
foreach ($phases as $i => $p) {
    $els[] = card($x, 180, $wSeg, 220, C_CARD, [
        para([run($p[0], 15, $i === 3 ? C_GREEN : C_ACCENT, true)], 'left', 105, 10),
        para([run($p[1], 12, C_MED)], 'left', 126),
    ]);
    if ($i < 3) { $els[] = sh('chev', $x + $wSeg + 2, 272, 20, 36, C_LIGHT, null, [], 'chevron'); }
    $x += $wSeg + 26;
}
$els[] = tb(60, 448, 1160, 110, [
    para([run('Prinsip: ', 12, C_TEXT, true), run('tiada kod baharu selepas freeze selain defect yang dibuktikan; setiap pembaikan masuk dengan regresi sendiri (corak yang sama untuk DEFECT-001/002). Status keseluruhan: CONDITIONAL GO — teknikal kukuh, baki kerja ialah semakan manual + keputusan produk.', 12, C_MED)], 'left', 126),
]);
$S[] = ['els' => $els, 'bg' => C_BG, 'notes' =>
    '1 minit. Tunjuk disiplin proses: fasa jelas, cadangan cadence (suite UAT setiap release), dan koridor defect→regression yang sudah terbukti dua kali.'];

/* 12 — Appendix: sources ------------------------------------------------------- */
$els = chrome('LAMPIRAN', 'Rujukan — semua angka boleh disemak', 12, $TOTAL);
$src = [
    ['docs/uat/UAT_SUMMARY.md', 'roll-up larian 64/64 + jadual defek + senarai semak sign-off'],
    ['docs/uat/DEFECTS.md', 'DEFECT-001/002 dengan bukti & status Resolved'],
    ['docs/uat/UAT_PRESENTATION.md', 'deck 12 slide untuk stakeholder bukan teknikal'],
    ['tests/uat/run_all.php', 'pelari suite ketiga-tiga role (self-cleaning)'],
    ['scratch/test_e2e_refund_gate.php', 'pengawal regresi gerbang refund — 13/13'],
    ['config/schema_update_v10–v13.sql', 'migrasi gerbang + pulangan + pengesanan tunai'],
];
foreach ($src as $i => $r) {
    $col = $i % 2; $row = intdiv($i, 2);
    $x = 60 + $col * 595; $yy = 150 + $row * 132;
    $els[] = card($x, $yy, 565, 112, C_CARD, [
        para([run($r[0], 12.5, C_ACCENT, true)], 'left', 108, 4),
        para([run($r[1], 11.5, C_MED)], 'left', 118),
    ]);
}
$S[] = ['els' => $els, 'bg' => C_BG, 'notes' =>
    'Rujuk silang bila SV nak bukti. Setiap angka dalam deck ini boleh surut ke fail ini; screenshot larian ada di akar repo (uat_*.png, verify_*.png).'];

/* 13 — Closing ----------------------------------------------------------------- */
$els = [];
$els[] = sh('rect', 0, 0, 1280, 720, C_DARK);
$els[] = tb(60, 240, 1160, 90, [para([run('Terima kasih — mari bincang 4 keputusan', 38, 'FFFFFF', true)], 'center', 105)]);
$els[] = sh('line', 540, 350, 200, 1, null, ['color' => C_AMBER, 'wPt' => 2]);
$els[] = tb(60, 380, 1160, 120, [
    para([run('Q1 gerbang refund · Q2 ambang AI hold · Q3 skop ujian viva · Q4 code-freeze', 15, C_ONDARK)], 'center', 130, 10),
    para([run('TrustMart · konsultasi supervisor · Okt 2026', 12, C_LIGHT)], 'center', 120),
]);
$S[] = ['els' => $els, 'bg' => C_DARK, 'notes' =>
    'Tutup dalam 30 saat: ulang 4 soalan, minta catatan keputusan SV, dan sahkan tarikh semakan seterusnya.'];

$out = $argv[1] ?? (__DIR__ . '/TrustMart_Konsultasi_SV.pptx');
write_pptx($out, $S);
