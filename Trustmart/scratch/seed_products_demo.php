<?php
/**
 * TrustMart — catalogue variety seed for the demo.
 *
 * The shipped catalogue covers only 3 of the 6 categories (Elektronik, Buku,
 * Pakaian), so the grid looked repetitive and the Makanan / Perkhidmatan /
 * Lain-lain filter chips returned nothing. This adds 11 products spread over
 * all six categories and over several verified sellers, each with a photo, and
 * fills in the two products that had no image.
 *
 * Deliberately NOT touched: trust_profiles, transactions, reviews, and the AI
 * columns — the seeded rows are plain catalogue data. Earbuds are given to
 * seller #20 on purpose: that account is AI-held, so it doubles as the
 * blocked-checkout demo with a desirable product.
 *
 * Safe to re-run: a product is skipped when the same seller already has that
 * exact name, and an image is only backfilled where image_path is empty.
 *
 * Run: php scratch/seed_products_demo.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$upload_dir = $root . '/uploads/products/';
$ALLOWED    = ['Elektronik', 'Buku', 'Pakaian', 'Makanan', 'Perkhidmatan', 'Lain-lain'];

// seller_id => the account must be an active penjual
$products = [
    // ── Elektronik ────────────────────────────────────────────
    ['seller' => 20, 'cat' => 'Elektronik', 'days' => 2,
     'name' => 'Earbuds TWS Bluetooth (Kotak Caj)',
     'desc' => 'Earbuds tanpa wayar dengan kotak caj, tahan 4-5 jam sekali cas. Keadaan: baharu, masih dalam kotak asal.',
     'price' => 79.00, 'stock' => 5, 'sold' => 3, 'img' => 'earbuds_tws.png'],
    ['seller' => 16, 'cat' => 'Elektronik', 'days' => 5,
     'name' => 'Mouse Gaming RGB Berkabel',
     'desc' => 'Mouse optik 3200 DPI dengan lampu RGB dan butang boleh disesuaikan. Keadaan: baik, berfungsi sepenuhnya.',
     'price' => 65.00, 'stock' => 6, 'sold' => 2, 'img' => 'mouse_gaming.png'],

    // ── Buku ──────────────────────────────────────────────────
    ['seller' => 4,  'cat' => 'Buku', 'days' => 9,
     'name' => 'Buku Fizik Tingkatan 5 KSSM',
     'desc' => 'Buku teks Fizik Tingkatan 5 KSSM, keadaan baik dengan catatan tipis pada beberapa halaman. Sesuai untuk SPM dan matrikulasi.',
     'price' => 22.00, 'stock' => 2, 'sold' => 1, 'img' => 'buku_fizik_t5.png'],
    ['seller' => 4,  'cat' => 'Buku', 'days' => 12,
     'name' => 'Novel Sastera Melayu (Rujukan SPM)',
     'desc' => 'Novel untuk Paper Sastera BM. Keadaan baik, halaman lengkap, sudah dibaca sekali.',
     'price' => 18.00, 'stock' => 3, 'sold' => 2, 'img' => 'novel_bm.png'],

    // ── Pakaian ───────────────────────────────────────────────
    ['seller' => 5,  'cat' => 'Pakaian', 'days' => 6,
     'name' => 'Hoodie Kapas Maroon (Saiz M)',
     'desc' => 'Hoodie kapas tebal warna maroon, saiz M, tanpa logo. Boleh dicuba sebelum beli di Kolej 13.',
     'price' => 55.00, 'stock' => 4, 'sold' => 1, 'img' => 'hoodie_maroon.png'],

    // ── Makanan ───────────────────────────────────────────────
    ['seller' => 5,  'cat' => 'Makanan', 'days' => 1,
     'name' => 'Kerepek Pisang Pedas 200g',
     'desc' => 'Kerepek pisang digoreng rangup dengan bumbu pedas. Bungkusan 200g, tahan sebulan. Siap ambil di kafe FKEE setiap petang.',
     'price' => 9.00, 'stock' => 20, 'sold' => 12, 'img' => 'kerepek_pisang.png'],
    ['seller' => 5,  'cat' => 'Makanan', 'days' => 1,
     'name' => 'Kopi Susu Cold Brew 250ml',
     'desc' => 'Kopi susu cold brew dibancuh pada hari yang sama, kurang manis. Disimpan sejuk; jumpa di lobi Kolej 13 selepas waktu Zohor.',
     'price' => 7.50, 'stock' => 24, 'sold' => 8, 'img' => 'kopi_susu_coldbrew.png'],

    // ── Perkhidmatan ──────────────────────────────────────────
    ['seller' => 15, 'cat' => 'Perkhidmatan', 'days' => 4,
     'name' => 'Servis Print & Jilid Tugasan (A4)',
     'desc' => 'Cetak dan jilid softcover untuk tugasan atau laporan projek. Harga seunit sehingga 100 halaman A4, siap dalam 24 jam.',
     'price' => 15.00, 'stock' => 30, 'sold' => 15, 'img' => 'servis_print_jilid.png'],
    ['seller' => 2,  'cat' => 'Perkhidmatan', 'days' => 7,
     'name' => 'Tuisyen Add Maths SPM (Online 1 Jam)',
     'desc' => 'Kelas tambahan Add Maths SPM selama satu jam secara dalam talian. Fokus topik lemah: kalkulus, vektor dan statistik.',
     'price' => 35.00, 'stock' => 10, 'sold' => 4, 'img' => 'tuisyen_perkhidmatan.png'],

    // ── Lain-lain ─────────────────────────────────────────────
    ['seller' => 2,  'cat' => 'Lain-lain', 'days' => 3,
     'name' => 'Botol Air Stainless Thermal 1L',
     'desc' => 'Botol air keluli tahan karat 1 liter, kekalkan sejuk sehingga 12 jam dengan penutup kedap. Sesuai dibawa ke kelas dan gimnasium.',
     'price' => 28.00, 'stock' => 9, 'sold' => 5, 'img' => 'botol_air_thermal.png'],
    ['seller' => 15, 'cat' => 'Lain-lain', 'days' => 8,
     'name' => 'Set Alatulis Art (12 Gradasi)',
     'desc' => 'Set alatulis grafit 12 gradasi berbeza bersama berus dan pemadam. Sesuai untuk kelas reka bentuk dan lukisan.',
     'price' => 25.00, 'stock' => 7, 'sold' => 3, 'img' => 'set_alatulis_art.png'],
];

// Products already in the catalogue that simply have no photo yet.
$backfill = [
    5  => 'casing_phone.png',
    11 => 'mechanical_keyboard.png',
];

echo "== TrustMart seed_products_demo ==\n";

// seller_id => full_name, validated once
$sellers = [];
foreach ($pdo->query("SELECT user_id, full_name FROM users WHERE role='penjual' AND is_active=1") as $r) {
    $sellers[(int)$r['user_id']] = $r['full_name'];
}

$insert = $pdo->prepare(
    "INSERT INTO products
        (seller_id, name, category, description, price, stock, sold_count,
         image_path, status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Aktif', (NOW() - INTERVAL ? DAY))"
);
$exists = $pdo->prepare("SELECT product_id FROM products WHERE seller_id=? AND name=? LIMIT 1");

$added = 0; $skipped = 0; $warned = 0;
foreach ($products as $p) {
    if (!in_array($p['cat'], $ALLOWED, true)) {
        echo "  SKIP   {$p['name']} — kategori tidak sah\n"; $warned++; continue;
    }
    if (!isset($sellers[$p['seller']])) {
        echo "  SKIP   {$p['name']} — penjual #{$p['seller']} tidak aktif/bukan penjual\n"; $warned++; continue;
    }
    $exists->execute([$p['seller'], $p['name']]);
    if ($id = $exists->fetchColumn()) {
        echo "  ADA    #{$id} {$p['name']}\n"; $skipped++; continue;
    }
    $img = null;
    if (is_file($upload_dir . $p['img'])) {
        $img = 'products/' . $p['img'];
    } else {
        echo "  NOTA   imej {$p['img']} tiada — produk tanpa gambar\n"; $warned++;
    }
    $insert->execute([$p['seller'], $p['name'], $p['cat'], $p['desc'],
                      $p['price'], $p['stock'], $p['sold'], $img, $p['days']]);
    $newId = (int)$pdo->lastInsertId();
    printf("  TAMBAH #%-3s %-38s %-13s RM%-7s stk=%-3s penjual=%s  img=%s\n",
        $newId, $p['name'], $p['cat'], number_format($p['price'], 2), $p['stock'],
        $sellers[$p['seller']], $img ?: '-');
    $added++;
}

$fix = $pdo->prepare("UPDATE products SET image_path=? WHERE product_id=? AND (image_path IS NULL OR image_path='')");
foreach ($backfill as $pid => $file) {
    if (!is_file($upload_dir . $file)) { echo "  NOTA   backfill #$pid: $file tiada\n"; $warned++; continue; }
    $fix->execute(['products/' . $file, $pid]);
    printf("  GAMBAR #%s <- %s (%s)\n", $pid, $file,
        $fix->rowCount() ? 'diisi' : 'sudah ada gambar');
}

echo "\n== Ringkasan katalog aktif ==\n";
foreach ($pdo->query("SELECT category, COUNT(*) n FROM products
                       WHERE status='Aktif' GROUP BY category ORDER BY category") as $r) {
    printf("  %-14s %s produk\n", $r['category'], $r['n']);
}
$total = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='Aktif'")->fetchColumn();
$noimg = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='Aktif'
                            AND (image_path IS NULL OR image_path='')")->fetchColumn();
echo "  Jml aktif: $total · tanpa gambar: $noimg\n";
echo "\nSelesai: $added ditambah, $skipped sudah wujud, $warned amaran.\n";
